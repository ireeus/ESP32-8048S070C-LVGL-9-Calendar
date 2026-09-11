<?php
session_start();

$config_file = __DIR__ . '/config.json';
if(!file_exists($config_file)) $config_file = __DIR__ . '/../config.json'; 

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>Access Denied</h2><p>Please log in via the main admin panel first.</p></div>");
}

$members_file = __DIR__ . '/ctta_members.json';
$members = file_exists($members_file) ? json_decode(file_get_contents($members_file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTTA | Admin Panel</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f9; margin: 0; padding: 20px; color:#1f2937;}
        .container { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #1f2937; color:#fff; padding: 20px; border-radius: 12px; margin-bottom: 20px;}
        .header h1 { margin: 0; font-size: 22px; text-transform: uppercase; }
        .header h1 span { color: #ffb703; }
        
        .grid-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        @media(max-width: 768px) { .grid-layout { grid-template-columns: 1fr; } }
        
        .card { background: #fff; border-radius: 12px; padding: 25px; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .card h2 { margin-top: 0; font-size: 18px; border-bottom: 2px solid #f3f4f6; padding-bottom: 10px; }
        
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 12px; font-weight: 700; color: #4b5563; margin-bottom: 5px; text-transform: uppercase; }
        input, select { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; box-sizing: border-box; }
        button { background: #ffb703; color: #000; border: none; padding: 12px 20px; font-weight: bold; border-radius: 6px; cursor: pointer; text-transform: uppercase; width: 100%; }
        button:hover { background: #fb8500; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px;}
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
        th { font-weight: 700; color: #6b7280; text-transform: uppercase; font-size: 11px; }
        .btn-del { background: #ef4444; color: #fff; padding: 6px 12px; width: auto; font-size: 11px;}
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>CT<span>TA</span> Admin Portal</h1>
        <a href="admin.php" style="color:#ffb703; text-decoration:none; font-weight:bold;">Back to CronTech Admin</a>
    </div>

    <div class="grid-layout">
        <div class="card">
            <h2>Register New Tester</h2>
            <form id="add-member-form" onsubmit="addMember(event)">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="m_name" required>
                </div>
                <div class="form-group">
                    <label>Company Name</label>
                    <input type="text" id="m_company" required>
                </div>
                <div class="form-group">
                    <label>Qualifications (e.g. C&G 2391)</label>
                    <input type="text" id="m_qual" required>
                </div>
                <div class="form-group">
                    <label>Insurance Expiry Date</label>
                    <input type="date" id="m_ins" required>
                </div>
                <div class="form-group">
                    <label>Calibration Expiry Date</label>
                    <input type="date" id="m_cal" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="m_status">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <button type="submit">Add to Register</button>
            </form>
        </div>

        <div class="card" style="overflow-x:auto;">
            <h2>Registered Testers Directory</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name & Company</th>
                        <th>Expiry Dates</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($members as $m): 
                        $is_expired = (strtotime($m['insurance_expiry']) < time()) || (strtotime($m['calibration_expiry']) < time());
                        $color = ($m['status'] === 'active' && !$is_expired) ? '#10b981' : '#ef4444';
                    ?>
                    <tr>
                        <td><strong><?php echo $m['id']; ?></strong></td>
                        <td>
                            <strong><?php echo htmlspecialchars($m['name']); ?></strong><br>
                            <span style="color:#6b7280; font-size:12px;"><?php echo htmlspecialchars($m['company']); ?></span>
                        </td>
                        <td style="font-size:11px;">
                            Ins: <?php echo $m['insurance_expiry']; ?><br>
                            Cal: <?php echo $m['calibration_expiry']; ?>
                        </td>
                        <td style="color:<?php echo $color; ?>; font-weight:bold; text-transform:uppercase;">
                            <?php echo $is_expired ? 'EXPIRED' : $m['status']; ?>
                        </td>
                        <td>
                            <button class="btn-del" onclick="deleteMember('<?php echo $m['id']; ?>')">Remove</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($members)): ?>
                    <tr><td colspan="5" style="text-align:center; color:#6b7280;">No members found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function addMember(e) {
    e.preventDefault();
    const payload = {
        name: document.getElementById('m_name').value,
        company: document.getElementById('m_company').value,
        qualifications: document.getElementById('m_qual').value,
        insurance_expiry: document.getElementById('m_ins').value,
        calibration_expiry: document.getElementById('m_cal').value,
        status: document.getElementById('m_status').value
    };

    fetch('ctta_api.php?action=add', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        alert(res.message);
        if(res.success) location.reload();
    });
}

function deleteMember(id) {
    if(!confirm('Are you sure you want to permanently remove ' + id + '?')) return;
    
    fetch('ctta_api.php?action=delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id: id})
    })
    .then(r => r.json())
    .then(res => {
        alert(res.message);
        if(res.success) location.reload();
    });
}
</script>
</body>
</html>
