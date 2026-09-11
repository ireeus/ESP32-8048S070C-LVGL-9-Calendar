<?php
// --- LOGIKA SERWERA ---
$filename = 'baza.txt';

$categories = [
    "Dokumentacja (EPC B/C)" => [
        "Fensa dla okien (od HA)", "Certyfikat Icynene (Dach)", "MCS: Panele + Bateria", "Nowy raport EPC"
    ],
    "Finanse (Let-to-Buy)" => [
        "Wycena 2-bed w okolicy", "Broker: Zdolność Let-to-Buy", "Decision in Principle (DIP)", "Aplikacja o kredyt BTL"
    ],
    "Legal Landlord" => [
        "Gas Safety (CP12)", "EICR (Elektryka)", "Landlord Insurance", "ICO Registration", "Right to Rent Check"
    ],
    "Zakup Nowego (3-Bed)" => [
        "Oferta na dom 3-pokojowy", "Stamp Duty (+5% surcharge)", "Finalizacja i przeprowadzka"
    ]
];

$allTasks = [];
foreach ($categories as $cat => $tList) { foreach ($tList as $t) $allTasks[] = $t; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = ($_POST['states'] ?? '') . "|" . ($_POST['val'] ?? '0') . "|" . ($_POST['mort'] ?? '0') . "|" . ($_POST['rent'] ?? '0');
    file_put_contents($filename, $data);
    echo "Zapisano postęp."; exit;
}

$saved = array_fill(0, count($allTasks), "0");
$v = $m = $r = "";
if (file_exists($filename) && filesize($filename) > 0) {
    $p = explode("|", file_get_contents($filename));
    $saved = explode(",", $p[0]);
    $v = $p[1] ?? ""; $m = $p[2] ?? ""; $r = $p[3] ?? "";
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Plan: Let-to-Buy UK</title>
    <style>
        :root {
            --p: #2563eb; --s: #64748b; --bg: #f8fafc; --dark: #0f172a;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); margin: 0; padding: 15px; color: #1e293b; }
        
        .card { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 20px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        
        h1 { font-size: 22px; font-weight: 800; margin: 0 0 20px 0; color: var(--dark); text-align: center; }
        
        /* Kalkulator Mobilny */
        .grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-bottom: 20px; }
        @media (min-width: 480px) { .grid { grid-template-columns: repeat(3, 1fr); } }
        
        .input-item { background: #f1f5f9; padding: 10px; border-radius: 12px; }
        label { display: block; font-size: 11px; font-weight: 700; color: var(--s); margin-bottom: 4px; text-transform: uppercase; }
        input { width: 100%; background: transparent; border: none; font-size: 18px; font-weight: 700; color: var(--dark); padding: 5px 0; outline: none; }
        
        .cf-box { background: var(--dark); color: white; padding: 20px; border-radius: 16px; text-align: center; margin-top: 10px; position: relative; overflow: hidden; }
        .cf-label { font-size: 12px; opacity: 0.8; font-weight: 500; }
        .cf-val { font-size: 36px; font-weight: 800; color: #4ade80; display: block; margin: 5px 0; }
        .cf-sub { font-size: 10px; opacity: 0.6; }

        /* Lista zadań */
        h2 { font-size: 13px; color: var(--p); margin: 25px 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid #eff6ff; }
        .task { display: flex; align-items: center; padding: 14px; background: #fff; border: 1px solid #f1f5f9; border-radius: 12px; margin-bottom: 8px; cursor: pointer; transition: 0.2s; }
        .task:active { transform: scale(0.98); background: #f8fafc; }
        
        input[type="checkbox"] { width: 22px; height: 22px; margin-right: 15px; border-radius: 6px; cursor: pointer; accent-color: var(--p); flex-shrink: 0; }
        .task-text { font-size: 14px; line-height: 1.4; font-weight: 500; }
        
        /* Przycisk */
        .btn-save { position: sticky; bottom: 20px; width: 100%; padding: 18px; background: var(--p); color: white; border: none; border-radius: 16px; font-size: 16px; font-weight: 700; box-shadow: 0 10px 15px -3px rgba(37,99,235,0.4); cursor: pointer; margin-top: 20px; }
        
        #msg { text-align: center; padding-top: 10px; font-size: 13px; font-weight: 600; color: #059669; }
    </style>
</head>
<body>

<div class="card">
    <h1>Plan: Dom & Wynajem</h1>

    <div class="grid">
        <div class="input-item"><label>Wycena (£)</label><input type="number" id="v" value="<?=$v?>" oninput="calc()"></div>
        <div class="input-item"><label>Dług (£)</label><input type="number" id="m" value="<?=$m?>" oninput="calc()"></div>
        <div class="input-item"><label>Czynsz (£)</label><input type="number" id="r" value="<?=$r?>" oninput="calc()"></div>
    </div>

    <div class="cf-box">
        <span class="cf-label">Miesięczny Cash Flow (Na czysto)</span>
        <span id="cf_val" class="cf-val">£0</span>
        <span id="details" class="cf-sub">Wpisz dane, by obliczyć zysk</span>
    </div>

    <?php $idx = 0; foreach ($categories as $cat => $ts): ?>
        <h2><?=$cat?></h2>
        <?php foreach ($ts as $t): ?>
            <label class="task">
                <input type="checkbox" class="c" id="t-<?=$idx?>" <?=(@$saved[$idx]=="1"?"checked":"")?>>
                <span class="task-text"><?=$t?></span>
            </label>
        <?php $idx++; endforeach; ?>
    <?php endforeach; ?>

    <button class="btn-save" onclick="save()">Zapisz postęp</button>
    <div id="msg"></div>
</div>

<script>
    function calc() {
        const v = parseFloat(document.getElementById('v').value) || 0;
        const m = parseFloat(document.getElementById('m').value) || 0;
        const r = parseFloat(document.getElementById('r').value) || 0;
        
        // Obliczanie max kapitału do wyciągnięcia (75% LTV)
        const maxEquity = (v * 0.75) - m;
        const totalLoan = m + (maxEquity > 0 ? maxEquity : 0);
        // Rata BTL (same odsetki - ok. 5.5% w 2026)
        const monthlyInt = (totalLoan * 0.055) / 12;
        
        // Zysk = Czynsz - Rata - 10% na ubezpieczenie/naprawy
        const cashflow = r - monthlyInt - (r * 0.10);
        
        const cfElement = document.getElementById('cf_val');
        cfElement.innerText = "£" + (cashflow > 0 ? Math.round(cashflow).toLocaleString() : 0);
        cfElement.style.color = cashflow > 0 ? "#4ade80" : "#f87171";
        
        document.getElementById('details').innerText = `Equity do wypłaty: £${Math.round(maxEquity > 0 ? maxEquity : 0).toLocaleString()} | Rata BTL: £${Math.round(monthlyInt)}`;
    }

    async function save() {
        const btn = document.querySelector('.btn-save');
        const states = Array.from(document.querySelectorAll('.c')).map(c => c.checked ? "1" : "0").join(',');
        
        const fd = new FormData();
        fd.append('states', states);
        fd.append('val', document.getElementById('v').value);
        fd.append('mort', document.getElementById('m').value);
        fd.append('rent', document.getElementById('r').value);

        btn.innerText = "Zapisywanie...";
        try {
            await fetch(window.location.href, { method: 'POST', body: fd });
            document.getElementById('msg').innerText = "✅ Zmiany zapisane na serwerze!";
            setTimeout(() => { document.getElementById('msg').innerText = ""; }, 3000);
        } catch (e) {
            alert("Błąd zapisu!");
        } finally {
            btn.innerText = "Zapisz postęp";
        }
    }
    window.onload = calc;
</script>

</body>
</html>

