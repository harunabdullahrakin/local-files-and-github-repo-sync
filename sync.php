<?php
// just fill the variables and put in local file and visit it. you can see the ui and work with it
ob_start();

// variables
// it automaticly checks your local files and sync with github repo. 
// if a file is in github but not in local then it will download that file 
// any file extra in local it will delete it
// highly customizeable with great ui yiu can select files in ui which to add/delete/downlaod/sync
// auto scans

$OWNER = ''; // github username
$REPO  = ''; // github repo name the user owns like portfolio-code

$TOKEN = ''; // fine gained token permanent (no expairy) with the repo access.
$ROOT_PATH = realpath(dirname(__DIR__)); 

// files to ignote ig
$EXCLUDE = [
    '.git'
];

if (isset($_GET['ajax'])) {

    ob_clean(); 

    error_reporting(0); 

    header('Content-Type: application/json');
    set_time_limit(0); 
    ini_set('memory_limit', '512M');

    $action = $_GET['ajax'];

    try {

        if (!function_exists('callGitHub')) {
            function callGitHub($endpoint, $isRaw = false) {
                global $TOKEN;
                $ch = curl_init($endpoint);
                $headers = [
                    "User-Agent: AmarEvents-Sync",
                    "Authorization: token $TOKEN"
                ];
                if ($isRaw) $headers[] = "Accept: application/vnd.github.v3.raw";

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_FAILONERROR => false 

                ]);

                $data = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) throw new Exception("cURL Error: $curlError");

                if ($httpCode === 401) throw new Exception("GitHub Unauthorized: Check your TOKEN.");
                if ($httpCode === 403) throw new Exception("GitHub Rate Limit Exceeded or Forbidden.");
                if ($httpCode >= 400) throw new Exception("GitHub API Error ($httpCode): " . substr($data, 0, 100));

                return $isRaw ? $data : json_decode($data, true);
            }
        }

        if (!function_exists('getLocalGitHash')) {
            function getLocalGitHash($path) {
                if (!file_exists($path)) return false;
                $content = file_get_contents($path);
                return sha1("blob " . strlen($content) . "\0" . $content);
            }
        }

        if (!function_exists('removeEmptySubFolders')) {
            function removeEmptySubFolders($path) {
                global $ROOT_PATH;
                if (!is_dir($path)) return;
                $items = scandir($path);
                foreach ($items as $item) {
                    if ($item == '.' || $item == '..') continue;
                    $fullPath = $path . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($fullPath)) removeEmptySubFolders($fullPath);
                }
                $items = array_diff(scandir($path), ['.', '..']);
                if (empty($items) && $path !== $ROOT_PATH) rmdir($path);
            }
        }

        if ($action === 'scan') {
            global $OWNER, $REPO, $ROOT_PATH, $EXCLUDE;

            $repoInfo = callGitHub("https://api.github.com/repos/$OWNER/$REPO");
            if (!isset($repoInfo['default_branch'])) {

                $msg = isset($repoInfo['message']) ? $repoInfo['message'] : "Unknown error fetching repo info";
                throw new Exception("Repo Access Failed: $msg");
            }

            $defaultBranch = $repoInfo['default_branch'];
            $treeUrl = "https://api.github.com/repos/$OWNER/$REPO/git/trees/$defaultBranch?recursive=1";
            $treeData = callGitHub($treeUrl);

            if (!isset($treeData['tree'])) throw new Exception("Could not fetch file tree.");

            $remoteFiles = [];
            $changes = ['create' => [], 'update' => [], 'delete' => []];

            foreach ($treeData['tree'] as $item) {
                if ($item['type'] !== 'blob') continue; 
                $path = $item['path'];

                if (in_array($path, $EXCLUDE)) continue;
                foreach ($EXCLUDE as $exc) {
                    if (strpos($path, $exc . '/') === 0) continue 2;
                }

                $remoteFiles[$path] = true;
                $localPath = $ROOT_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

                if (!file_exists($localPath)) {
                    $changes['create'][] = ['path' => $path, 'size' => $item['size'] ?? 0];
                } else {
                    $localHash = getLocalGitHash($localPath);
                    if ($localHash !== $item['sha']) {
                        $changes['update'][] = ['path' => $path, 'size' => $item['size'] ?? 0];
                    }
                }
            }

            if (is_dir($ROOT_PATH)) {
                $rii = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($ROOT_PATH, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($rii as $file) {
                    if ($file->isDir()) continue;
                    $fullPath = $file->getPathname();
                    $relPath = str_replace($ROOT_PATH . DIRECTORY_SEPARATOR, '', $fullPath);
                    $relPath = str_replace('\\', '/', $relPath);

                    if (in_array($relPath, $EXCLUDE)) continue;
                    foreach ($EXCLUDE as $exc) {
                        if (strpos($relPath, $exc . '/') === 0) continue 2;
                    }

                    if (!isset($remoteFiles[$relPath])) {
                        $changes['delete'][] = ['path' => $relPath];
                    }
                }
            }

            echo json_encode(['status' => 'success', 'data' => $changes]);
            exit;
        }

        if ($action === 'deploy') {
            $input = json_decode(file_get_contents('php://input'), true);
            $files = $input['files'] ?? [];
            $results = [];

            foreach ($files as $file) {
                $type = $file['type']; 
                $rPath = $file['path'];
                $lPath = $ROOT_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rPath);

                if (in_array($rPath, $EXCLUDE)) {
                    $results[] = ['path' => $rPath, 'status' => 'Skipped (Excluded)'];
                    continue;
                }

                try {
                    if ($type === 'delete') {
                        if (file_exists($lPath)) {
                            unlink($lPath);
                            $results[] = ['path' => $rPath, 'status' => 'Deleted'];
                        }
                    } else {
                        $dir = dirname($lPath);
                        if (!is_dir($dir)) mkdir($dir, 0755, true);

                        $content = callGitHub("https://api.github.com/repos/$OWNER/$REPO/contents/$rPath", true);
                        file_put_contents($lPath, $content);
                        $results[] = ['path' => $rPath, 'status' => ($type === 'create' ? 'Created' : 'Updated')];
                    }
                } catch (Exception $e) {
                    $results[] = ['path' => $rPath, 'status' => 'Error', 'msg' => $e->getMessage()];
                }
            }

            removeEmptySubFolders($ROOT_PATH);
            echo json_encode(['status' => 'success', 'results' => $results]);
            exit;
        }

    } catch (Exception $e) {

        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amar Events | Master Sync</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#1e293b', 900: '#0f172a', 950: '#020617' }
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #020617; color: #e2e8f0; }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .custom-scroll::-webkit-scrollbar { width: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: #1e293b; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #4f46e5; border-radius: 4px; }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center py-8 px-4 font-sans selection:bg-indigo-500 selection:text-white">
    <div class="w-full max-w-5xl mb-6 flex flex-col md:flex-row justify-between items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-indigo-400 to-purple-400">
                Amar Events <span class="text-slate-500 font-mono text-xl">SyncHub</span>
            </h1>
            <p class="text-slate-400 text-sm mt-1">
                Target: <span class="font-mono text-indigo-400"><?php echo basename($ROOT_PATH); ?></span> 
                &bull; Repo: <span class="font-mono text-indigo-400"><?php echo $REPO; ?></span>
            </p>
        </div>

        <button onclick="startScan()" id="scanBtn" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl font-semibold shadow-lg shadow-indigo-500/20 transition-all flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            Check for Updates
        </button>
<a href="/admin/upload" class="bg-indigo-600 hover:bg-indigo-500 text-white px-8 py-3 rounded-lg font-bold">
         Upload to Github
    </a>
    </div>

    <div id="loader" class="hidden w-full max-w-5xl py-20 text-center">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-indigo-500 border-t-transparent mb-4"></div>
        <p class="text-lg text-slate-300 animate-pulse" id="loaderText">Analyzing file structure...</p>
    </div>

    <div id="dashboard" class="hidden w-full max-w-5xl grid grid-cols-1 md:grid-cols-3 gap-6">

        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden flex flex-col h-[600px]">
            <div class="p-4 border-b border-green-900/30 bg-green-950/10 flex justify-between items-center">
                <h3 class="font-bold text-green-400 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    New Files
                </h3>
                <span id="count-create" class="bg-green-900 text-green-300 text-xs px-2 py-1 rounded-full">0</span>
            </div>
            <div class="flex-1 overflow-y-auto custom-scroll p-2 space-y-1 pb-32" id="list-create"></div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden flex flex-col h-[600px]">
            <div class="p-4 border-b border-blue-900/30 bg-blue-950/10 flex justify-between items-center">
                <h3 class="font-bold text-blue-400 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    Modified
                </h3>
                <span id="count-update" class="bg-blue-900 text-blue-300 text-xs px-2 py-1 rounded-full">0</span>
            </div>
            <div class="flex-1 overflow-y-auto custom-scroll p-2 space-y-1 pb-32" id="list-update"></div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden flex flex-col h-[600px] mb-8">
            <div class="p-4 border-b border-red-900/30 bg-red-950/10 flex justify-between items-center">
                <h3 class="font-bold text-red-400 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    Orphans
                </h3>
                <span id="count-delete" class="bg-red-900 text-red-300 text-xs px-2 py-1 rounded-full">0</span>
            </div>
            <div class="flex-1 overflow-y-auto custom-scroll p-2 space-y-1 pb-32" id="list-delete"></div>
        </div>

    </div>

    <div id="actionBar" class="hidden fixed bottom-6 left-1/2 transform -translate-x-1/2 bg-slate-800 border border-slate-700 p-4 rounded-xl shadow-2xl flex items-center gap-4 z-50 w-[90%] max-w-2xl backdrop-blur-md bg-opacity-90">
        <div class="flex-1">
            <p class="text-white font-bold">Ready to sync</p>
            <p class="text-xs text-slate-400">Select files above and click Execute.</p>
        </div>
        <button onclick="executeSync()" class="bg-indigo-600 hover:bg-indigo-500 text-white px-8 py-3 rounded-lg font-bold shadow-lg transition transform active:scale-95">
            Execute Sync
        </button>
    </div>

    <div id="consoleOverlay" class="fixed inset-0 bg-black/80 backdrop-blur-sm hidden flex items-center justify-center z-[60]">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-2xl rounded-xl shadow-2xl overflow-hidden flex flex-col max-h-[80vh]">
            <div class="p-4 bg-slate-950 border-b border-slate-800 flex justify-between">
                <h3 class="text-white font-mono">Deployment Log</h3>
                <button onclick="location.reload()" class="text-slate-400 hover:text-white">&times;</button>
            </div>
            <div id="consoleOutput" class="p-4 font-mono text-xs overflow-y-auto flex-1 space-y-1 text-slate-300"></div>
            <div class="p-4 border-t border-slate-800 bg-slate-950">
                <button onclick="location.reload()" class="w-full py-2 bg-slate-800 hover:bg-slate-700 text-white rounded">Close & Refresh</button>
            </div>
        </div>
    </div>

    <script>
        let scanData = null;

        async function startScan() {
            document.getElementById('scanBtn').classList.add('hidden');
            document.getElementById('dashboard').classList.add('hidden');
            document.getElementById('actionBar').classList.add('hidden');
            document.getElementById('loader').classList.remove('hidden');

            try {
                const req = await fetch('?ajax=scan');
                const res = await req.json();

                if (res.status === 'error') throw new Error(res.message);

                scanData = res.data;
                renderLists();

                document.getElementById('loader').classList.add('hidden');
                document.getElementById('dashboard').classList.remove('hidden');

                const totalChanges = scanData.create.length + scanData.update.length + scanData.delete.length;
                if(totalChanges > 0) {
                    document.getElementById('actionBar').classList.remove('hidden');
                    document.getElementById('actionBar').classList.add('flex');
                } else {
                    alert("Everything is already in sync!");
                    document.getElementById('scanBtn').classList.remove('hidden');
                }

            } catch (err) {
                alert('Error: ' + err.message);
                document.getElementById('loader').classList.add('hidden');
                document.getElementById('scanBtn').classList.remove('hidden');
            }
        }

        function renderLists() {
            const types = ['create', 'update', 'delete'];

            types.forEach(type => {
                const listEl = document.getElementById(`list-${type}`);
                const countEl = document.getElementById(`count-${type}`);
                listEl.innerHTML = '';
                countEl.innerText = scanData[type].length;

                if (scanData[type].length === 0) {
                    listEl.innerHTML = `<div class="text-center text-slate-600 italic text-sm py-10">No files to ${type}</div>`;
                    return;
                }

                scanData[type].forEach((item, index) => {
                    const row = document.createElement('div');
                    row.className = 'flex items-start gap-3 p-3 rounded hover:bg-slate-800/50 transition border border-transparent hover:border-slate-700/50';

                    let colorClass = type === 'create' ? 'text-green-400' : (type === 'update' ? 'text-blue-400' : 'text-red-400');
                    let sizeText = item.size ? `<span class="text-xs text-slate-500 ml-2">(${formatBytes(item.size)})</span>` : '';

                    row.innerHTML = `
                        <input type="checkbox" id="${type}-${index}" checked class="mt-1 w-4 h-4 rounded bg-slate-700 border-slate-600 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-slate-900 cursor-pointer item-checkbox" data-type="${type}" data-path="${item.path}">
                        <label for="${type}-${index}" class="text-sm break-all cursor-pointer select-none">
                            <span class="${colorClass} font-mono block mb-0.5">${item.path}</span>
                            ${sizeText}
                        </label>
                    `;
                    listEl.appendChild(row);
                });
            });
        }

        async function executeSync() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            if (checkboxes.length === 0) return alert("Please select at least one file.");

            const payload = [];
            checkboxes.forEach(cb => {
                payload.push({
                    type: cb.dataset.type,
                    path: cb.dataset.path
                });
            });

            if(!confirm(`Are you sure you want to process ${payload.length} files?`)) return;

            document.getElementById('consoleOverlay').classList.remove('hidden');
            const con = document.getElementById('consoleOutput');
            con.innerHTML = '<div class="text-indigo-400 animate-pulse">Initializing deployment sequence...</div>';

            try {
                const req = await fetch('?ajax=deploy', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ files: payload })
                });
                const res = await req.json();

                if (res.status === 'error') throw new Error(res.message);

                con.innerHTML = '';
                res.results.forEach(r => {
                    const color = r.status === 'Deleted' ? 'text-red-400' : (r.status === 'Error' ? 'text-orange-400' : 'text-green-400');
                    con.innerHTML += `<div class="border-b border-slate-800/50 py-1"><span class="${color} font-bold w-20 inline-block">[${r.status.toUpperCase()}]</span> ${r.path} ${r.msg ? '- '+r.msg : ''}</div>`;
                });

                con.innerHTML += '<div class="mt-4 text-white font-bold bg-green-900/20 p-2 border border-green-900 rounded text-center">✓ Batch Completed (Dirs Cleaned)</div>';

            } catch (err) {
                con.innerHTML += `<div class="text-red-500 font-bold mt-4">CRITICAL ERROR: ${err.message}</div>`;
            }
        }

        function formatBytes(bytes, decimals = 0) {
            if (!+bytes) return '0 B';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
        }
    </script>
</body>
</html>
