<?php
// click uplaod to upload all files to github repo no git needed no external package.
$gitUser = 'github_username';
$gitRepo = 'repo-name'; 
$gitBranch = 'branch';
$gitToken = 'token'; 
$committerName  = "commiter_name_any_name_will_work";
$committerEmail = "github_email";

$outputLogs = "";
$statusMsg = "";

set_time_limit(300); 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_upload'])) {

    $projectRoot = realpath(__DIR__ . '/../');
    $remoteUrl = "https://{$gitUser}:{$gitToken}@github.com/{$gitUser}/{$gitRepo}.git";

    putenv("HOME={$projectRoot}"); 
    chdir($projectRoot);

    $cmds = [];

    if (!file_exists($projectRoot . '/.git')) {
        $cmds[] = "git init";
        $cmds[] = "git config --global --add safe.directory '{$projectRoot}'";
    }

    $cmds[] = "git config user.email '{$committerEmail}'";
    $cmds[] = "git config user.name '{$committerName}'";

    $cmds[] = "git remote remove origin 2>&1"; 
    $cmds[] = "git remote add origin {$remoteUrl}";

    $cmds[] = "git add .";

    $timestamp = date('Y-m-d H:i:s');
    $cmds[] = "git commit -m 'Update from Alwaysdata: {$timestamp}'";

    $cmds[] = "git push -u origin {$gitBranch} --force";

    $outputLogs .= "<div class='log-line info'><strong>Working Directory:</strong> {$projectRoot}</div>";

    foreach ($cmds as $cmd) {
        $result = shell_exec($cmd . " 2>&1");
        
        $displayCmd = str_replace($gitToken, '******', $cmd);
        
        $displayResult = str_replace($remoteUrl, 'https://***@github.com/...', $result ?? '');
        $displayResult = str_replace($gitToken, '******', $displayResult);

        $outputLogs .= "<div class='cmd'>$ {$displayCmd}</div>";
        if (!empty($displayResult)) {
            $outputLogs .= "<pre>{$displayResult}</pre>";
        }
    }

    $statusMsg = "Process Finished.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Git Sync Manager</title>
    
    <style>
        :root {
            --bg-color: #0d1117;
            --card-bg: #161b22;
            --border-color: #30363d;
            --primary-btn: #238636;
            --primary-hover: #2ea043;
            --secondary-btn: #1f6feb;
            --secondary-hover: #388bfd;
            --text-main: #c9d1d9;
            --text-dim: #8b949e;
            --accent-red: #da3633;
            --accent-bg-red: #3c1618;
            --cmd-color: #7ee787;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            display: flex;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 900px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            height: fit-content;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }

        .header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            background: #21262d;
            border-top-left-radius: 6px;
            border-top-right-radius: 6px;
        }

        h1 { font-size: 1.5rem; font-weight: 600; color: #fff; }

        .content { padding: 20px; }

        .warning-box {
            background: var(--accent-bg-red);
            border: 1px solid var(--accent-red);
            color: #ff7b72;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        code {
            background: rgba(255,255,255,0.1);
            padding: 2px 5px;
            border-radius: 4px;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        }

        .action-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 25px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid rgba(240,246,252,0.1);
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s cubic-bezier(0.3, 0, 0.5, 1);
            color: #ffffff;
            width: 100%;
        }

        .btn-upload { background-color: var(--primary-btn); }
        .btn-upload:hover { background-color: var(--primary-hover); }

        .btn-sync { background-color: var(--secondary-btn); }
        .btn-sync:hover { background-color: var(--secondary-hover); }

        .terminal-window {
            background: #000;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0;
            overflow: hidden;
            margin-top: 10px;
        }

        .terminal-header {
            background: #21262d;
            padding: 8px 15px;
            font-size: 12px;
            color: var(--text-dim);
            border-bottom: 1px solid var(--border-color);
            font-family: monospace;
        }

        .logs {
            padding: 15px;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 13px;
            max-height: 400px;
            overflow-y: auto;
        }

        .cmd { color: var(--cmd-color); margin-top: 12px; font-weight: bold; }
        .log-line { margin-bottom: 5px; color: var(--text-dim); }
        .log-line.info { color: #58a6ff; }
        pre { color: #c9d1d9; margin: 4px 0 0 10px; white-space: pre-wrap; word-break: break-all; }

        @media (max-width: 600px) {
            .action-grid { grid-template-columns: 1fr; }
            .header { padding: 15px; }
            h1 { font-size: 1.25rem; }
            body { padding: 10px; }
        }
    </style>
</head>
<body>

<div class="container">
  
    <div class="header">
        <h1>Admin / Git Manager</h1>
    </div>

    <div class="content">
      
        <div class="warning-box">
            <strong>System Notice:</strong> This process performs a force push to the origin. It will upload contents from <code><?php echo realpath(__DIR__ . '/../'); ?></code> to GitHub. Ensure critical sensitive data is excluded via <code>.gitignore</code>.
        </div>

        <div class="action-grid">
            <form method="POST" style="width: 100%;">
                <button type="submit" name="do_upload" class="btn btn-upload">
                    Start Upload
                </button>
            </form>
            
            <a href="/sync" class="btn btn-sync">
                Sync Data
            </a>
        </div>

        <?php if ($statusMsg || $outputLogs): ?>
            <div class="terminal-window">
                <div class="terminal-header">Console Output</div>
                <div class="logs">
                    <?php echo $outputLogs; ?>
                    <?php if($statusMsg): ?>
                        <div class="cmd" style="color: #fff;">> <?php echo $statusMsg; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
