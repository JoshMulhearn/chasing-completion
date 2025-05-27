<?php
    session_start();
    if(!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']){
        header("Location: Error.php?code=not_logged_in_user_stats");
        exit();
    }
    if (!isset($_SESSION['userData'])) {
        header("Location: Error.php?code=session_data_missing_user_stats");
        exit();
    }


    $username = isset($_SESSION['userData']['name']) ? htmlspecialchars($_SESSION['userData']['name']) : 'Guest';
    $avatar = isset($_SESSION['userData']['avatar']) ? htmlspecialchars($_SESSION['userData']['avatar']) : 'Images/default_avatar.png';
    $steamID64 = isset($_SESSION['userData']['steam_id']) ? $_SESSION['userData']['steam_id'] : null;

    // --- Prepare data for Pie Chart: Games Completion ---
    $total_games_with_achievements = 0;
    $games_100_completed = 0;

    // Ensure dashboard_stats and its sub-keys exist before accessing
    if (isset($_SESSION['dashboard_stats']) && isset($_SESSION['dashboard_stats']['games_with_achievements'])) {
        $total_games_with_achievements = count($_SESSION['dashboard_stats']['games_with_achievements']);
    }
    if (isset($_SESSION['dashboard_stats']) && isset($_SESSION['dashboard_stats']['games_100_count'])) {
        $games_100_completed = (int)$_SESSION['dashboard_stats']['games_100_count'];
    }

    $games_not_100_completed = $total_games_with_achievements - $games_100_completed;
    if ($games_not_100_completed < 0) {
        $games_not_100_completed = 0; // Sanity check
    }

    $pie_chart_data_php = [
        'labels' => ['100% Completed', 'Not Yet 100% Completed'],
        'datasets' => [[
            'data' => [$games_100_completed, $games_not_100_completed],
            'backgroundColor' => [
                'rgba(75, 192, 192, 0.7)', // Teal/Greenish for completed
                'rgba(201, 203, 207, 0.7)'  // Grey for not completed
            ],
            'borderColor' => [
                'rgba(75, 192, 192, 1)',
                'rgba(201, 203, 207, 1)'
            ],
            'hoverOffset' => 4,
            'borderWidth' => 1
        ]]
    ];

    // --- Prepare data for Bar Chart: Top 5 Most Played Games ---
    $all_owned_games = $_SESSION['userData']['owned_games']['games'] ?? [];
    $bar_chart_data_php = ['labels' => [], 'datasets' => [['label' => 'Hours Played', 'data' => [], 'backgroundColor' => 'rgba(54, 162, 235, 0.7)', 'borderColor' => 'rgba(54, 162, 235, 1)', 'borderWidth' => 1]]]; // Default empty structure

    if (!empty($all_owned_games)) {
        // Filter out games with no playtime and sort
        $games_with_playtime = array_filter($all_owned_games, function($game){
            return isset($game['playtime_forever']) && $game['playtime_forever'] > 0;
        });

        if (!empty($games_with_playtime)) {
            usort($games_with_playtime, function($a, $b) {
                return ($b['playtime_forever'] ?? 0) <=> ($a['playtime_forever'] ?? 0);
            });
            $top_5_games = array_slice($games_with_playtime, 0, 5);

            $bar_chart_labels = [];
            $bar_chart_playtimes_hours = [];
            foreach ($top_5_games as $game) {
                $bar_chart_labels[] = $game['name'];
                $bar_chart_playtimes_hours[] = round(($game['playtime_forever'] ?? 0) / 60, 1);
            }
            $bar_chart_data_php['labels'] = $bar_chart_labels;
            $bar_chart_data_php['datasets'][0]['data'] = $bar_chart_playtimes_hours;
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!--FONTS-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Genos:ital,wght@0,100..900;1,100..900&family=Orbitron:wght@400..900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <!---->
    <!--STYLE SHEETS-->
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/style.css">
    <!---->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <!-- Include Chart.js -->
    <title>Your Gaming Stats - Chasing Completion</title>
</head>
<body>
    <?php

        include 'navbar.php';
    ?>

    <h1 class="user-stats-title">User Stats</h1>

    <div class="charts-grid-container">
        <!--pie chart-->
        <div class="chart-container" id="pieChartCard">
            <h3>Games Completion Status</h3>
            <?php if ($total_games_with_achievements > 0 || $games_100_completed > 0) : //if there is data show chart ?>
                <canvas id="gamesCompletionPieChart"></canvas>
            <?php else: ?>
                <p class="no-data-message-charts">Completion data is not yet available. This chart populates based on games with achievements found in your library.</p>
            <?php endif; ?>
        </div>

        <!--bar chart-->
        <div class="chart-container" id="barChartCard">
            <h3>Top 5 Most Played Games</h3>
            <?php if (!empty($bar_chart_data_php['labels'])): ?>
                <canvas id="mostPlayedBarChart"></canvas>
            <?php else: ?>
                <p class="no-data-message-charts">No playtime data found for your games, or you haven't played any games yet.</p>
            <?php endif; ?>
        </div>

    </div>

    <?php include 'footer.php'?>
    <script src="javascript/bar-menu.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        Chart.defaults.color = '#ffffff'; 
        Chart.defaults.borderColor = '#ffffff';

        //pie chart
        const pieChartDataFromPHP = <?php echo json_encode($pie_chart_data_php); ?>;
        const totalGamesForPie = <?php echo $total_games_with_achievements; ?>;
        const gamesCompletedForPie = <?php echo $games_100_completed; ?>;
        const pieChartCanvas = document.getElementById('gamesCompletionPieChart');

        if (pieChartCanvas && (totalGamesForPie > 0 || gamesCompletedForPie > 0) ) { //if pie chart exists and there is data
            new Chart(pieChartCanvas, {
                type: 'pie',
                data: pieChartDataFromPHP,
                options: {
                    responsive: true,
                    maintainAspectRatio: true, 
                    plugins: {
                        legend: {
                            position: 'bottom', 
                            labels: {
                                color: '#ffffff', 
                                font: { size: 14, family: 'Roboto' }
                            }
                        },
                        title: {
                            display: false, 
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        label += context.parsed + ' game(s)';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }


        //bar chart
        const barChartDataFromPHP = <?php echo json_encode($bar_chart_data_php); ?>;
        const barChartCanvas = document.getElementById('mostPlayedBarChart');

        if (barChartCanvas && barChartDataFromPHP.labels.length > 0) { //if bar chart exists and has labels
            new Chart(barChartCanvas, {
                type: 'bar',
                data: barChartDataFromPHP,
                options: {
                    indexAxis: 'y', //bar chart is horizontal
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false 
                        },
                        title: {
                            display: false 
                        },
                        tooltip: {
                             callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.x !== null) {
                                        label += context.parsed.x + ' hours';
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { //horizontal bars
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Hours Played',
                                color: '#ffffff',
                                font: { size: 14, family: 'Roboto' }
                            },
                            ticks: {
                                color: '#ffffff', //x-axis tick labels
                                font: { family: 'Roboto' }
                            },
                            grid: {
                                color: 'light-grey' //color of X-axis grid lines
                            }
                        },
                        y: { //categories
                            ticks: {
                                color: '#ffffff', //game names
                                font: { family: 'Roboto' }
                            },
                            grid: {
                                display: false //hide y-axis grid lines
                            }
                        }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>