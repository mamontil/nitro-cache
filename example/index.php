<?php
require_once __DIR__ . '/../vendor/autoload.php';

use NitroCache\Client as NitroCache;

if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $cache = new NitroCache(512);

        $cache->set('_ping', '1', 5);
        if ($cache->get('_ping') !== '1') {
            throw new \Exception("Engine not responding");
        }

        $currentStats = $cache->getStats();
    } catch (\Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'NitroCache Server Connection Failed: ' . $e->getMessage()]);
        exit;
    }

    switch ($_GET['action']) {
        case 'full_bench':
            $count = (int)$_GET['count'];

            // WRITE Test
            $s1 = microtime(true);
            for ($i = 1; $i <= $count; $i++) { $cache->set("u_$i", "payload_$i", 3600); }
            $dt_w = microtime(true) - $s1;

            // READ Test
            $s2 = microtime(true);
            for ($i = 1; $i <= $count; $i++) { $cache->get("u_$i"); }
            $dt_r = microtime(true) - $s2;

            echo json_encode([
                'w_ops' => round($count / $dt_w),
                'w_time' => round($dt_w, 4),
                'r_ops' => round($count / $dt_r),
                'r_time' => round($dt_r, 4),
                'stats' => $cache->getStats()
            ]);
            break;

        case 'manual_set':
            $t1 = hrtime(true);
            $cache->set($_GET['k'], $_GET['v'], 3600);
            $time = (hrtime(true) - $t1) / 1000;
            echo json_encode(['time' => round($time, 2), 'stats' => $cache->getStats()]);
            break;

        case 'manual_get':
            $t1 = hrtime(true);
            $val = $cache->get($_GET['k']);
            $time = (hrtime(true) - $t1) / 1000;
            echo json_encode(['val' => $val ?? 'NOT FOUND', 'time' => round($time, 2)]);
            break;

        case 'clear':
            $cache->clear();
            echo json_encode(['stats' => $cache->getStats()]);
            break;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NitroCache Control Center 🚀</title>
    <style>
        :root { --bg: #0d1117; --panel: #161b22; --blue: #58a6ff; --green: #3fb950; --border: #30363d; --grey: #8b949e; }
        body { background: var(--bg); color: #c9d1d9; font-family: 'Segoe UI', system-ui, sans-serif; padding: 30px; margin: 0; }
        .container { max-width: 1100px; margin: 0 auto; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .ram-box { background: #21262d; border: 1px solid var(--blue); padding: 12px 25px; border-radius: 10px; font-weight: bold; font-size: 18px; box-shadow: 0 0 15px rgba(88, 166, 255, 0.2); }
        h2 { margin-top: 0; color: var(--blue); font-size: 14px; text-transform: uppercase; letter-spacing: 1.5px; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; }
        input { width: 100%; background: #0d1117; border: 1px solid var(--border); color: white; padding: 12px; border-radius: 6px; box-sizing: border-box; margin-bottom: 15px; outline: none; font-family: monospace; }
        input:focus { border-color: var(--blue); }
        .btn { cursor: pointer; border: none; padding: 14px; border-radius: 8px; font-weight: bold; width: 100%; transition: 0.2s; font-size: 13px; text-transform: uppercase; }
        .btn-blue { background: var(--blue); color: #0d1117; }
        .btn-green { background: var(--green); color: #0d1117; }
        .btn-clear { background: transparent; border: 1px solid #f85149; color: #f85149; margin-top: 20px; }
        .btn-clear:hover { background: #f85149; color: white; }
        .res-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #21262d; font-family: 'JetBrains Mono', monospace; font-size: 13px; }
        .val { color: var(--green); font-weight: bold; }
        .label-sm { font-size: 11px; color: var(--grey); margin-bottom: 5px; display: block; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>NitroCache Engine 🚀</h1>
        <div class="ram-box">MEM: <span id="ram-usage">0.00</span> MB</div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>📊 Batch Stress Test</h2>
            <span class="label-sm">Number of keys per iteration:</span>
            <input type="number" id="bench-count" value="50000">
            <button class="btn btn-blue" onclick="runFullBench()" id="bench-btn">Run SET + GET Cycle</button>

            <div id="bench-res" style="margin-top: 20px;">
                <div style="color: var(--blue); font-size: 12px; margin-bottom: 5px;">WRITE RESULTS:</div>
                <div class="res-row">Throughput: <span id="w-ops" class="val">-</span></div>
                <div class="res-row">Time Elapsed: <span id="w-time" class="val">-</span></div>

                <div style="color: var(--blue); font-size: 12px; margin-top: 15px; margin-bottom: 5px;">READ RESULTS:</div>
                <div class="res-row">Throughput: <span id="r-ops" class="val">-</span></div>
                <div class="res-row">Time Elapsed: <span id="r-time" class="val">-</span></div>
            </div>
        </div>

        <div class="card">
            <h2>🤝 Manual Control (Single Key)</h2>
            <div style="display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <span class="label-sm">Key:</span>
                    <input type="text" id="m-key" placeholder="user_123">
                </div>
                <div style="flex: 1;">
                    <span class="label-sm">Value:</span>
                    <input type="text" id="m-val" placeholder="Hello World">
                </div>
            </div>
            <button class="btn btn-green" onclick="manualSet()">Store Data</button>

            <div style="margin-top: 30px; border-top: 1px solid var(--border); padding-top: 20px;">
                <span class="label-sm">Enter key to fetch:</span>
                <input type="text" id="g-key" placeholder="user_123">
                <button class="btn" style="background: #8957e5; color: white;" onclick="manualGet()">Retrieve Value</button>

                <div id="get-res" style="margin-top: 15px; padding: 15px; background: #0d1117; border-radius: 8px;">
                    <div class="res-row" style="border:none;">
                        <span>Result:</span>
                        <span id="manual-val" style="color: white; font-weight: bold;">...</span>
                    </div>
                    <div class="res-row" style="border:none; color: var(--grey); font-size: 11px;">
                        <span>Latency:</span>
                        <span id="manual-time">-</span>
                    </div>
                </div>
            </div>

            <button class="btn btn-clear" onclick="clearCache()">Flush Cache (Clear)</button>
        </div>
    </div>
</div>

<script>
  // Helper to handle fetch errors
  async function safeFetch(url) {
    const response = await fetch(url);
    if (!response.ok) {
      const data = await response.json();
      alert("⚠️ Connection Error: " + (data.error || "Server is offline"));
      throw new Error(data.error);
    }
    return response.json();
  }

  async function updateStats(stats) {
    if(stats && stats.usage_mb !== undefined) {
      document.getElementById('ram-usage').innerText = parseFloat(stats.usage_mb).toFixed(2);
      document.getElementById('ram-usage').style.color = 'blue';
    }
  }

  async function runFullBench() {
    const count = document.getElementById('bench-count').value;
    const btn = document.getElementById('bench-btn');
    btn.innerText = "⏳ EXECUTING...";
    btn.disabled = true;

    try {
      const res = await safeFetch(`?action=full_bench&count=${count}`);

      document.getElementById('w-ops').innerText = res.w_ops.toLocaleString() + " ops/s";
      document.getElementById('w-time').innerText = res.w_time + " sec";

      document.getElementById('r-ops').innerText = res.r_ops.toLocaleString() + " ops/s";
      document.getElementById('r-time').innerText = res.r_time + " sec";

      updateStats(res.stats);
    } catch(e) {
      console.error(e);
    } finally {
      btn.innerText = "Run SET + GET Cycle";
      btn.disabled = false;
    }
  }

  async function manualSet() {
    const k = document.getElementById('m-key').value;
    const v = document.getElementById('m-val').value;
    if(!k) return;
    try {
      const res = await safeFetch(`?action=manual_set&k=${k}&v=${v}`);
      document.getElementById('manual-val').innerText = "OK (Stored)";
      document.getElementById('manual-time').innerText = res.time + " μs";
      updateStats(res.stats);
    } catch(e) {}
  }

  async function manualGet() {
    const k = document.getElementById('g-key').value;
    if(!k) return;
    try {
      const res = await safeFetch(`?action=manual_get&k=${k}`);
      document.getElementById('manual-val').innerText = res.val;
      document.getElementById('manual-time').innerText = res.time + " μs";
    } catch(e) {}
  }

  async function clearCache() {
    if(!confirm("Are you sure you want to flush the entire cache?")) return;
    try {
      const res = await safeFetch('?action=clear');
      document.getElementById('manual-val').innerText = "CLEARED";
      document.getElementById('w-ops').innerText = "-";
      document.getElementById('r-ops').innerText = "-";

      updateStats(res.stats);
    } catch(e) {
      console.error("Clear failed", e);
    }
  }

  // Initial stats fetch - safe check
  window.onload = async () => {
    try {
      const res = await safeFetch('?action=manual_get&k=ping');
      updateStats(res.stats);
    } catch(e) {
      document.getElementById('ram-usage').innerText = "OFFLINE";
      document.getElementById('ram-usage').style.color = "#f85149";
    }
  };
</script>

</body>
</html>