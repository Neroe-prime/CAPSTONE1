<?php
/**
 * Holt's Linear Trend Method (Double Exponential Smoothing) Helper
 * Computes smoothed daily demand level (L_t), trend (T_t), 7-day / 14-day demand projections,
 * projected ending stock, and estimated days until depletion or reorder threshold.
 */

if (!function_exists('calculateHoltInventoryForecast')) {
    /**
     * Build a 14-day daily dispensing time series for a specific inventory item
     * from consultations and inventory_audit, then apply Holt's Linear Trend smoothing.
     *
     * @param PDO   $pdo           Active PDO connection
     * @param array $item          Row from `inventory` table
     * @param float $alpha         Level smoothing factor (0 < alpha < 1), default 0.40
     * @param float $beta          Trend smoothing factor (0 < beta < 1), default 0.25
     * @param int   $history_days  Number of historical days in series, default 14
     * @return array
     */
    function calculateHoltInventoryForecast(PDO $pdo, array $item, float $alpha = 0.40, float $beta = 0.25, int $history_days = 14): array
    {
        $item_id = (int)($item['item_id'] ?? 0);
        $item_name = (string)($item['item_name'] ?? '');
        $current_stock = (int)($item['current_stock'] ?? 0);
        $reorder_level = (int)($item['reorder_level'] ?? 15);
        $stored_rate = (float)($item['daily_consumption_rate'] ?? 0);

        // 1. Collect daily dispensed counts over the last $history_days days
        $daily_usage = [];
        $date_keys = [];
        for ($i = $history_days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $date_keys[] = $d;
            $daily_usage[$d] = 0.0;
        }

        $start_date = $date_keys[0];
        $has_audit_history = false;

        try {
            $audit_stmt = $pdo->prepare("
                SELECT DATE(created_at) AS log_date, SUM(quantity) AS qty
                FROM inventory_audit
                WHERE item_id = ?
                  AND action_type = 'Dispensed'
                  AND DATE(created_at) >= ?
                GROUP BY DATE(created_at)
            ");
            $audit_stmt->execute([$item_id, $start_date]);
            $audit_rows = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($audit_rows)) {
                $has_audit_history = true;
                foreach ($audit_rows as $r) {
                    $d = $r['log_date'];
                    if (isset($daily_usage[$d])) {
                        $daily_usage[$d] += (float)$r['qty'];
                    }
                }
            }
        } catch (PDOException $e) {
            // Fallback to consultations if inventory_audit query fails
        }

        // If no audit rows exist yet for older seeded data, read from consultations
        if (!$has_audit_history) {
            try {
                $cons_stmt = $pdo->prepare("
                    SELECT DATE(consultation_date) AS log_date, SUM(quantity_used) AS qty
                    FROM consultations
                    WHERE inventory_item_id = ?
                      AND DATE(consultation_date) >= ?
                    GROUP BY DATE(consultation_date)
                ");
                $cons_stmt->execute([$item_id, $start_date]);
                foreach ($cons_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $d = $r['log_date'];
                    if (isset($daily_usage[$d])) {
                        $daily_usage[$d] += (float)$r['qty'];
                    }
                }
            } catch (PDOException $e) {
                // Keep zero series if unavailable
            }
        }

        $series = array_values($daily_usage);
        $total_dispensed = array_sum($series);

        // Fallback if historical dispensing is 0 but an explicit daily_consumption_rate was set on the item
        if ($total_dispensed <= 0 && $stored_rate > 0) {
            $level = $stored_rate;
            $trend = 0.0;
        } elseif ($total_dispensed <= 0) {
            $level = 0.0;
            $trend = 0.0;
        } else {
            // 2. Initialize Holt's Linear Trend: L_0 = Y_0, T_0 = Y_1 - Y_0
            $level = $series[0];
            $trend = count($series) > 1 ? ($series[1] - $series[0]) : 0.0;

            for ($t = 1; $t < count($series); $t++) {
                $y_t = $series[$t];
                $prev_level = $level;
                $prev_trend = $trend;

                $level = ($alpha * $y_t) + ((1.0 - $alpha) * ($prev_level + $prev_trend));
                $trend = ($beta * ($level - $prev_level)) + ((1.0 - $beta) * $prev_trend);
            }

            // Clamp negative smoothed values to zero for physical inventory demand
            $level = max(0.0, $level);
        }

        // 3. Project h-day ahead demand: Y_hat(t + h) = max(0, L_t + h * T_t)
        $forecast_7d_demand = 0.0;
        $forecast_14d_demand = 0.0;
        for ($h = 1; $h <= 14; $h++) {
            $projected_day = max(0.0, $level + ($h * $trend));
            if ($h <= 7) {
                $forecast_7d_demand += $projected_day;
            }
            $forecast_14d_demand += $projected_day;
        }

        $forecast_7d_demand = round($forecast_7d_demand, 1);
        $forecast_14d_demand = round($forecast_14d_demand, 1);
        $projected_stock_7d = max(0, (int)round($current_stock - $forecast_7d_demand));
        $projected_stock_14d = max(0, (int)round($current_stock - $forecast_14d_demand));

        // 4. Simulate day-by-day depletion using Holt's trend up to 90 days
        $days_until_depletion = null;
        $days_until_reorder = null;
        $has_usage = ($level > 0.01 || $trend > 0.01);

        if ($has_usage) {
            if ($current_stock <= 0) {
                $days_until_depletion = 0;
            }
            if ($current_stock <= $reorder_level) {
                $days_until_reorder = 0;
            }

            $sim_stock = (float)$current_stock;
            for ($day = 1; $day <= 90; $day++) {
                $day_demand = max(0.0, $level + ($day * $trend));
                if ($day_demand <= 0.001 && $trend <= 0) {
                    break;
                }
                $sim_stock -= $day_demand;

                if ($days_until_reorder === null && $sim_stock <= $reorder_level) {
                    $days_until_reorder = $day;
                }
                if ($days_until_depletion === null && $sim_stock <= 0) {
                    $days_until_depletion = $day;
                    break;
                }
            }
        }

        // 5. Determine trend direction label
        if (!$has_usage) {
            $trend_direction = 'No Recent Dispensing';
        } elseif ($trend > 0.05) {
            $trend_direction = 'Rising Demand';
        } elseif ($trend < -0.05) {
            $trend_direction = 'Declining Demand';
        } else {
            $trend_direction = 'Steady Demand';
        }

        // 6. Stock health classification
        if ($current_stock <= 0) {
            $stock_status = 'Out of Stock';
        } elseif ($current_stock <= $reorder_level) {
            $stock_status = 'Low Stock';
        } else {
            $stock_status = 'Sufficient';
        }

        $is_watchlist = (
            $current_stock <= 0 ||
            $current_stock <= $reorder_level ||
            ($days_until_depletion !== null && $days_until_depletion <= 7) ||
            ($days_until_reorder !== null && $days_until_reorder <= 5)
        );

        return [
            'item_id' => $item_id,
            'item_name' => $item_name,
            'batch_number' => $item['batch_number'] ?? 'N/A',
            'current_stock' => $current_stock,
            'reorder_level' => $reorder_level,
            'level' => round($level, 2),
            'trend' => round($trend, 2),
            'has_usage' => $has_usage,
            'trend_direction' => $trend_direction,
            'forecast_7d_demand' => $forecast_7d_demand,
            'forecast_14d_demand' => $forecast_14d_demand,
            'projected_stock_7d' => $projected_stock_7d,
            'projected_stock_14d' => $projected_stock_14d,
            'days_until_depletion' => $days_until_depletion,
            'days_until_reorder' => $days_until_reorder,
            'stock_status' => $stock_status,
            'is_watchlist' => $is_watchlist
        ];
    }

    /**
     * Compute Holt's Linear Trend forecasts across all inventory items,
     * sorted by urgency (stock-outs/watchlist first, then lowest stock).
     */
    function getAllHoltInventoryForecasts(PDO $pdo, float $alpha = 0.40, float $beta = 0.25): array
    {
        $results = [];
        try {
            $stmt = $pdo->query("SELECT * FROM inventory ORDER BY current_stock ASC, item_name ASC");
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                $results[] = calculateHoltInventoryForecast($pdo, $item, $alpha, $beta);
            }
        } catch (PDOException $e) {
            return [];
        }

        usort($results, function ($a, $b) {
            if ($a['is_watchlist'] !== $b['is_watchlist']) {
                return $a['is_watchlist'] ? -1 : 1;
            }
            $a_days = $a['days_until_depletion'] ?? 999;
            $b_days = $b['days_until_depletion'] ?? 999;
            if ($a_days !== $b_days) {
                return $a_days <=> $b_days;
            }
            return $a['current_stock'] <=> $b['current_stock'];
        });

        return $results;
    }
}