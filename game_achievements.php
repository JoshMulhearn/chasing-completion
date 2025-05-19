<?php
    session_start(); // Start the session at the very beginning

    // --- Steam Web API Key ---
    // !! IMPORTANT: Replace 'YOUR_STEAM_API_KEY' with your actual Steam Web API key.
    // !! Store your API key securely, e.g., in an environment variable or a non-public config file.
    $steam_api_key = 'CF9C8D9F6B8A4AD8DBE9D6DB383949E5'; // <<=== REPLACE THIS WITH YOUR ACTUAL KEY
    $username = $_SESSION['userData']['name']; 
    $avatar = $_SESSION['userData']['avatar'];

    // Check if the user is logged in; if not, redirect to an error page or login page
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: Error.php"); // Or your login page
        exit();
    }

    // Ensure userData and SteamID exist in the session
    if (!isset($_SESSION['userData']['steam_id'])) {
        error_log("Error in game_achievements.php: SteamID not found in session.");
        header("Location: Error.php?code=steamid_missing"); // Or your login page
        exit();
    }
    $steamID64 = $_SESSION['userData']['steam_id'];
    $username = $_SESSION['userData']['name'] ?? 'Player';

    // Get the AppID from the URL query parameter
    if (!isset($_GET['appid']) || !is_numeric($_GET['appid'])) {
        // AppID is missing or not numeric, redirect or show error
        // You might want a more user-friendly error page here
        die("Error: Game AppID is missing or invalid.");
    }
    $appId = filter_var($_GET['appid'], FILTER_VALIDATE_INT);

    // --- Fetch Player Achievements from Steam Web API ---
    $achievementsData = null;
    $gameName = 'Game'; // Default game name
    $apiErrorMessage = '';

    if ($steam_api_key !== 'YOUR_STEAM_API_KEY' && !empty($steam_api_key)) {
        $achievements_url = "https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/?key={$steam_api_key}&steamid={$steamID64}&appid={$appId}&l=english&format=json";
        
        // Context options for file_get_contents, e.g., to set a timeout
        $context_options = [
            'http' => [
                'timeout' => 10, // Timeout in seconds
                'ignore_errors' => true // Fetch content even on HTTP errors to inspect body if needed
            ],
            // If you suspect SSL/TLS issues, you might need to configure SSL context options,
            // but this can have security implications and should be understood before use.
            // 'ssl' => [
            //     'verify_peer' => false, // DANGEROUS: Disables SSL peer verification
            //     'verify_peer_name' => false, // DANGEROUS: Disables SSL peer name verification
            // ],
        ];
        $context = stream_context_create($context_options);
        
        $achievements_json = @file_get_contents($achievements_url, false, $context);

        if ($achievements_json === false) {
            $last_error = error_get_last();
            $detailed_error = $last_error ? " (Details: " . htmlspecialchars($last_error['message']) . ")" : "";
            $apiErrorMessage = "Could not connect to Steam API to fetch achievements. Please try again later." . $detailed_error;
            error_log("Failed to fetch achievements for appid {$appId}, steamid {$steamID64}. Error: " . ($last_error['message'] ?? 'Unknown error'));
        } else {
            // Check HTTP status code if possible (requires more advanced HTTP client like cURL or Guzzle usually)
            // For file_get_contents with ignore_errors, $http_response_header is populated.
            // Example: sscanf($http_response_header[0], 'HTTP/%*d.%*d %d', $http_status_code);
            // if ($http_status_code >= 400) { ... handle client/server HTTP errors ... }

            $decoded_data = json_decode($achievements_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $apiErrorMessage = "Error decoding JSON response from Steam API. Response was: " . htmlspecialchars(substr($achievements_json, 0, 200)) . "...";
                error_log("JSON Decode Error for achievements appid {$appId}, steamid {$steamID64}. Error: " . json_last_error_msg() . ". Response: " . $achievements_json);
            } elseif (isset($decoded_data['playerstats']['success']) && $decoded_data['playerstats']['success'] == true) {
                $achievementsData = $decoded_data['playerstats']['achievements'] ?? [];
                $gameName = htmlspecialchars($decoded_data['playerstats']['gameName'] ?? 'Game');
                if (empty($achievementsData) && !isset($decoded_data['playerstats']['error'])) {
                    $apiErrorMessage = "No achievements found for this game, or they haven't been set up for your account yet.";
                }
            } elseif (isset($decoded_data['playerstats']['error'])) {
                $apiErrorMessage = "Steam API Error: " . htmlspecialchars($decoded_data['playerstats']['error']);
            } else {
                $apiErrorMessage = "Could not retrieve achievements. The game might not have achievements, or your profile settings might restrict access. Unexpected API response format.";
                error_log("Unexpected API response for achievements appid {$appId}, steamid {$steamID64}: " . $achievements_json);
            }
        }
    } else {
        $apiErrorMessage = "Steam API key is not configured by the site administrator. Achievements cannot be loaded.";
    }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Genos:ital,wght@0,100..900;1,100..900&family=Orbitron:wght@400..900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/navbar.css">
    <link rel="stylesheet" href="css/style.css"> 
    <title><?php echo htmlspecialchars($gameName); ?> - Achievements</title>
    <style>
        body {
            font-family: 'Roboto', sans-serif;
        }
        .container {
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .achievement-list {
            list-style-type: none;
            padding: 0;
        }
        .achievement-item {
            display: flex;
            align-items: flex-start; /* Align items to the top */
            padding: 15px;
            border-bottom: 1px solid #eee;
            margin-bottom: 10px;
            border-radius: 5px;
            background-color: #f9f9f9;
        }
        .achievement-item:last-child {
            border-bottom: none;
        }
        .achievement-item.achieved {
            background-color: #e6ffed; /* Light green for achieved */
            border-left: 5px solid #4CAF50; /* Green accent */
        }
        .achievement-item.not-achieved {
            background-color: #fff0f0; /* Light red for not achieved */
            border-left: 5px solid #f44336; /* Red accent */
            opacity: 0.8;
        }
        .achievement-icon {
            width: 64px;
            height: 64px;
            margin-right: 15px;
            border-radius: 4px;
            object-fit: contain; /* So icons don't stretch */
            flex-shrink: 0; /* Prevent icon from shrinking */
        }
        .achievement-details {
            flex-grow: 1;
        }
        .achievement-name {
            font-size: 1.2em;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        .achievement-description {
            font-size: 0.9em;
            color: #555;
            margin-bottom: 8px;
        }
        .achievement-status {
            font-size: 0.8em;
            font-style: italic;
        }
        .achieved .achievement-status {
            color: #2e7d32; /* Darker green */
        }
        .not-achieved .achievement-status {
            color: #c62828; /* Darker red */
        }
        .api-error-message {
            color: #D8000C; /* Red for errors */
            background-color: #FFD2D2; /* Light red background */
            border: 1px solid #D8000C;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        .page-title {
            text-align: center;
            margin-bottom: 30px;
            font-family: 'Orbitron', sans-serif;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 8px 15px;
            background-color: #555;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        .back-link:hover {
            background-color: #333;
        }

    </style>
</head>
<body>
    <?php 
        include 'navbar.php'; // Assuming your navbar.php is in the same directory
    ?>

    <div class="container">
        <a href="games.php" class="back-link">&laquo; Back to Games List</a>
        <h1 class="page-title">Achievements for <?php echo htmlspecialchars($gameName); ?></h1>

        <?php if (!empty($apiErrorMessage)): ?>
            <p class="api-error-message"><?php echo $apiErrorMessage; ?></p>
        <?php endif; ?>

        <?php if (empty($apiErrorMessage) && $achievementsData !== null && !empty($achievementsData)): ?>
            <ul class="achievement-list">
                <?php foreach ($achievementsData as $achievement): ?>
                    <?php
                        $isAchieved = (bool)($achievement['achieved'] ?? 0);
                        $achievedClass = $isAchieved ? 'achieved' : 'not-achieved';
                        $iconUrl = $isAchieved ? ($achievement['icon'] ?? '') : ($achievement['icongray'] ?? '');
                        // Fallback if specific achieved/not-achieved icon is missing, use the general one
                        if(empty($iconUrl) && isset($achievement['icon'])) $iconUrl = $achievement['icon'];
                        if(empty($iconUrl) && isset($achievement['icongray'])) $iconUrl = $achievement['icongray'];


                        $displayName = htmlspecialchars($achievement['displayName'] ?? $achievement['name'] ?? 'Unnamed Achievement');
                        $description = htmlspecialchars($achievement['description'] ?? 'No description available.');
                        $unlockTimestamp = $achievement['unlocktime'] ?? 0;
                        $unlockTimeFormatted = $isAchieved && $unlockTimestamp > 0 ? date('F j, Y \a\t g:i a', $unlockTimestamp) : 'Not unlocked';
                    ?>
                    <li class="achievement-item <?php echo $achievedClass; ?>">
                        <?php if (!empty($iconUrl)): ?>
                            <img src="<?php echo htmlspecialchars($iconUrl); ?>" alt="<?php echo $displayName; ?> icon" class="achievement-icon" onerror="this.style.display='none';">
                        <?php else: ?>
                            <div class="achievement-icon" style="background-color: #ccc; text-align:center; line-height:64px;">ICO</div> <?php endif; ?>
                        <div class="achievement-details">
                            <div class="achievement-name"><?php echo $displayName; ?></div>
                            <div class="achievement-description"><?php echo $description; ?></div>
                            <div class="achievement-status">
                                <?php echo $isAchieved ? 'Achieved on: ' . $unlockTimeFormatted : 'Status: Locked'; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif (empty($apiErrorMessage) && ($achievementsData === null || empty($achievementsData))): ?>
            <p style="text-align:center;">This game may not have any achievements, or they could not be loaded.</p>
        <?php endif; ?>
    </div>

    <?php 
        include 'footer.php'; // Assuming your footer.php is in the same directory
    ?>
    </body>
</html>
