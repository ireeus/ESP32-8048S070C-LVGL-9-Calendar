<?php
session_start();

// Redirect to login if not authenticated
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: admin.php");
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// SECURE LOCATION OUTSIDE ACCESSIBLE WEB DIRECTORY
$apps_file = __DIR__ . '/../ctta_applications.json';
$applications = file_exists($apps_file) ? json_decode(file_get_contents($apps_file), true) : [];
if (!is_array($applications)) $applications = [];

$members_file = __DIR__ . '/../ctta_members.json';
$members = file_exists($members_file) ? json_decode(file_get_contents($members_file), true) : [];
if (!is_array($members)) $members = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTTA Management | CronTech</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f9; margin: 0; padding: 20px; color:#1f2937;}
        .container { max-width: 1400px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb; margin-bottom: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);}
        .header h1 { margin: 0; color: #1f2937; font-size: 22px; text-transform: uppercase; }
        .header h1 span { color: #ffb703; }
        .btn { padding: 10px 15px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; font-size:13px; font-weight:700;}
        .btn-logout { background: transparent; border: 1px solid #d1d5db; color: #4b5563; }
        .btn-logout:hover { color: #111827; background: #f3f4f6; }
        .btn-accept { background: #3b82f6; color: white; margin-bottom: 5px; width: 100%; box-sizing: border-box;}
        .btn-reject { background: #ef4444; color: white; margin-bottom: 5px; width: 100%; box-sizing: border-box;}
        .btn-delete { background: #111827; color: white; margin-bottom: 5px; width: 100%; box-sizing: border-box;}
        
        .toolbar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 15px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .tab-btn { background: #fff; border: 1px solid #e5e7eb; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; color: #4b5563; transition: all 0.2s; }
        .tab-btn:hover { background: #f3f4f6; color: #111827; }
        .tab-btn.active { background: #1f2937; color: #fff; border-color: #1f2937; }

        .table-wrapper { overflow-x: auto; background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);}
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { background: #f9fafb; font-weight: 600; color: #4b5563; text-transform: uppercase; font-size: 13px;}
        tr:hover { background: #f9fafb; }
        
        .settings-panel { background: #fff; border: 1px solid #e5e7eb; padding: 25px; border-radius: 12px; margin-top: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);}
        .settings-panel h2 { color: #1f2937; margin-top: 0; border-bottom: 2px solid #f4f7f9; padding-bottom: 10px;}
        .settings-panel label { font-size: 13px; font-weight: 600; color: #4b5563; display:block; margin-top:15px;}
        .settings-panel input, .settings-panel select { padding: 10px; margin: 5px 0 0 0; width: 100%; display:block; border:1px solid #d1d5db; background: #fff; color: #1f2937; border-radius:6px; box-sizing:border-box;}
        .settings-panel button { background: #ffb703; color: #000; padding: 15px 30px; font-weight: bold; text-transform: uppercase; margin-top: 25px; width: 100%; border:none; border-radius:8px; cursor:pointer;}

        .ctta-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        @media(max-width: 768px){ .ctta-grid { grid-template-columns: 1fr; } }
        .btn-del { background: #ef4444; color: #fff; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold; text-transform: uppercase; margin-top:4px;}
        .btn-edit { background: #3b82f6; color: #fff; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold; text-transform: uppercase; margin-right: 4px; margin-top:4px;}
        .btn-card { background: #ffb703; color: #000; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold; text-transform: uppercase; margin-right: 4px; margin-top:4px;}

        /* MODALS */
        .modal-overlay { position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); display:none; justify-content:center; align-items:center; z-index:9999; }
        .modal-box { background:#fff; padding:30px; border-radius:12px; width:90%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.15); max-height:90vh; overflow-y:auto; }

        /* CR80 CREDIT CARD PRINT STYLES */
        .cr80-card-wrapper { width: 85.6mm; height: 53.9mm; background: #111827; color: #ffffff; border-radius: 3.18mm; padding: 4mm; box-sizing: border-box; position: relative; font-family: 'Segoe UI', Arial, sans-serif; border: 1px solid #374151; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; box-shadow: 0 10px 20px rgba(0,0,0,0.2); margin: 0 auto; }
        .cr80-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #374151; padding-bottom: 2mm; }
        .cr80-title { font-size: 8pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .cr80-title span { color: #ffb703; }
        .cr80-badge { background: #ffb703; color: #000; font-size: 5pt; font-weight: 800; padding: 1px 4px; border-radius: 2px; text-transform: uppercase; }
        .cr80-body { display: flex; gap: 3mm; align-items: center; margin-top: 1mm; }
        .cr80-photo { width: 16mm; height: 20mm; border-radius: 2mm; object-fit: cover; border: 1px solid #ffb703; background: #1f2937; flex-shrink: 0; }
        .cr80-info { flex: 1; overflow: hidden; }
        .cr80-name { font-size: 8pt; font-weight: 800; color: #ffffff; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
        .cr80-comp { font-size: 6pt; color: #9ca3af; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
        .cr80-id { font-size: 6.5pt; font-weight: 800; color: #ffb703; margin-top: 1mm; }
        .cr80-qr { width: 16mm; height: 16mm; background: #ffffff; padding: 1mm; border-radius: 1.5mm; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
        .cr80-qr img { width: 100% !important; height: 100% !important; }
        .cr80-footer { border-top: 1px solid #374151; padding-top: 1.5mm; display: flex; justify-content: space-between; font-size: 5pt; color: #9ca3af; font-weight: 600; }

        @media print {
            body * { visibility: hidden; }
            .modal-overlay, .modal-box { background: transparent !important; box-shadow: none !important; }
            #print-card-area, #print-card-area * { visibility: visible; }
            #print-card-area { position: absolute; left: 0; top: 0; margin: 0; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>CTTA<span> Management</span></h1>
            <div>
                <a href="admin.php" class="btn btn-logout" style="margin-right:10px;">← Back to Bookings</a>
                <a href="?logout=1" class="btn btn-logout">Logout</a>
            </div>
        </div>

        <div class="toolbar">
            <div class="tabs">
                <button class="tab-btn active" onclick="filterTab('ctta', this)">CTTA Register (<?php echo count($members); ?>)</button>
                <button class="tab-btn" onclick="filterTab('ctta_apps', this)">CTTA Applications (<?php echo count($applications); ?>)</button>
            </div>
        </div>

        <!-- CTTA VIEW -->
        <div id="view-ctta">
            <div class="ctta-grid">
                <div class="settings-panel" style="margin-top:0;">
                    <h2>Register New CTTA Tester</h2>
                    <form onsubmit="addCttaMember(event)" enctype="multipart/form-data">
                        <label>Full Name</label>
                        <input type="text" id="m_name" required>
                        <label>Company Name</label>
                        <input type="text" id="m_company" required>
                        <label>Qualifications (Comma separated)</label>
                        <input type="text" id="m_qual" required placeholder="e.g. C&G 2391, 18th Edition">
                        <label>Insurance Expiry Date</label>
                        <input type="date" id="m_ins" required>
                        <label>Calibration Expiry Date</label>
                        <input type="date" id="m_cal" required>
                        <label>Status</label>
                        <select id="m_status">
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                        </select>
                        <label>Tester Photo</label>
                        <input type="file" id="m_photo" accept="image/*" style="padding: 10px; background: #f9fafb; border: 1px solid #d1d5db; border-radius: 6px; width: 100%; box-sizing:border-box;">
                        <button type="submit" style="margin-top:15px;">Add to Register</button>
                    </form>
                </div>

                <div class="table-wrapper">
                    <table style="min-width: 100%;">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>ID & Name</th>
                                <th>Expiry Dates</th>
                                <th>Documents</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($members as $m): 
                                $is_expired = (strtotime($m['insurance_expiry']) < time()) || (strtotime($m['calibration_expiry']) < time());
                                $color = ($m['status'] === 'active' && !$is_expired) ? '#10b981' : '#ef4444';
                                
                                $photo_data = '';
                                if (!empty($m['photo']) && file_exists($m['photo'])) {
                                    $type = pathinfo($m['photo'], PATHINFO_EXTENSION);
                                    $photo_data = 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents($m['photo']));
                                } else {
                                    $photo_data = "https://ui-avatars.com/api/?name=" . urlencode($m['name']) . "&background=random";
                                }
                            ?>
                            <tr>
                                <td>
                                    <img src="<?php echo $photo_data; ?>" alt="Photo" style="width: 45px; height: 45px; border-radius: 8px; object-fit: cover; border: 1px solid #d1d5db;">
                                </td>
                                <td>
                                    <strong><?php echo $m['id']; ?></strong><br>
                                    <span style="color:#1f2937; font-weight:600; font-size:14px;"><?php echo htmlspecialchars($m['name']); ?></span><br>
                                    <span style="color:#6b7280; font-size:12px;"><?php echo htmlspecialchars($m['company']); ?></span>
                                </td>
                                <td style="font-size:12px; color:#4b5563; font-weight:600;">
                                    Ins: <?php echo $m['insurance_expiry']; ?><br>
                                    Cal: <?php echo $m['calibration_expiry']; ?>
                                </td>
                                <td style="font-size:11px; line-height: 1.6;">
                                    <?php 
                                    if (!empty($m['docs']) && is_array($m['docs'])) {
                                        $docs_list = [];
                                        foreach($m['docs'] as $type => $path) {
                                            if ($type === 'doc_photo') continue; 
                                            $filename = basename($path);
                                            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                            
                                            $display_name = str_replace('doc_', '', $type);
                                            if (strpos($type, 'doc_qualifications') !== false) $display_name = 'Qualifications';
                                            if ($type === 'doc_insurance') $display_name = 'Insurance';
                                            if ($type === 'doc_calibration') $display_name = 'Calibration';
                                            
                                            $docs_list[] = [
                                                'url' => 'view_doc.php?file=' . urlencode($filename),
                                                'isPdf' => ($ext === 'pdf'),
                                                'title' => addslashes($display_name)
                                            ];
                                        }
                                        $docs_json = htmlspecialchars(json_encode($docs_list), ENT_QUOTES, 'UTF-8');

                                        $idx = 0;
                                        foreach($m['docs'] as $type => $path): 
                                            if ($type === 'doc_photo') continue;
                                            $display_name = $docs_list[$idx]['title'];
                                    ?>
                                        <a href="javascript:void(0);" onclick="openDocGallery(<?php echo $docs_json; ?>, <?php echo $idx; ?>)" style="color:#2563eb; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:4px; padding: 2px 0;">
                                            📄 <?php echo $display_name; ?>
                                        </a><br>
                                    <?php 
                                            $idx++;
                                        endforeach; 
                                    } else {
                                        echo "<span style='color:#9ca3af;'>No files attached</span>";
                                    }
                                    ?>
                                </td>
                                <td style="color:<?php echo $color; ?>; font-weight:bold; text-transform:uppercase;">
                                    <?php echo $is_expired ? 'EXPIRED' : $m['status']; ?>
                                </td>
                                <td>
                                    <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                        <button class="btn-card" onclick='openCardModal(<?php echo json_encode($m); ?>, "<?php echo $photo_data; ?>")'>🪪 ID Card</button>
                                        <button class="btn-edit" onclick='openEditModal(<?php echo json_encode($m); ?>)'>Edit</button>
                                        <button class="btn-del" onclick="deleteCttaMember('<?php echo $m['id']; ?>')">Remove</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($members)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#6b7280; padding:30px;">No members found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- CTTA APPLICATIONS VIEW -->
        <div id="view-ctta-apps" style="display:none;">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Date & ID</th>
                            <th>Applicant Details</th>
                            <th>Qualifications Declared</th>
                            <th>Uploaded Documents</th>
                            <th>Status</th>
                            <th style="min-width: 130px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($applications as $app): 
                            $status = $app['status'] ?? 'Pending';
                            $status_color = '#fb8500'; 
                            if (strpos($status, 'Refunded') !== false) {
                                $status_color = '#10b981'; 
                            } elseif (strpos($status, 'Failed') !== false) {
                                $status_color = '#ef4444'; 
                            }

                            // Load applicant photo thumbnail if provided
                            $app_photo_path = $app['docs']['doc_photo'] ?? '';
                            $app_photo_data = '';
                            if (!empty($app_photo_path) && file_exists($app_photo_path)) {
                                $type = pathinfo($app_photo_path, PATHINFO_EXTENSION);
                                $app_photo_data = 'data:image/' . $type . ';base64,' . base64_encode(file_get_contents($app_photo_path));
                            } else {
                                $app_photo_data = "https://ui-avatars.com/api/?name=" . urlencode($app['name']) . "&background=random";
                            }
                        ?>
                        <tr>
                            <td>
                                <img src="<?php echo $app_photo_data; ?>" alt="Applicant Photo" style="width: 48px; height: 48px; border-radius: 8px; object-fit: cover; border: 2px solid #ffb703; cursor: pointer;" onclick="openDocGallery([{'url':'<?php echo $app_photo_data; ?>', 'isPdf':false, 'title':'Applicant Biometric Photo'}], 0)">
                            </td>
                            <td>
                                <strong style="color:#1f2937;"><?php echo date('d M Y', strtotime($app['date'])); ?></strong><br>
                                <small style="color:#6b7280;"><?php echo $app['id']; ?></small>
                            </td>
                            <td>
                                <strong style="color:#1f2937;"><?php echo htmlspecialchars($app['name']); ?></strong><br>
                                <span style="color:#6b7280; font-size:12px;"><?php echo htmlspecialchars($app['company']); ?></span><br>
                                <a href="mailto:<?php echo htmlspecialchars($app['email']); ?>" style="color:#2563eb; font-size:12px; text-decoration:none; font-weight:600;"><?php echo htmlspecialchars($app['email']); ?></a><br>
                                <small style="color:#6b7280;"><?php echo htmlspecialchars($app['phone']); ?></small>
                            </td>
                            <td style="font-size:13px; color:#1f2937;">
                                <?php echo htmlspecialchars($app['qualifications']); ?><br>
                                <small style="color:#6b7280; font-weight:600;">Ins No: <?php echo htmlspecialchars($app['insurance_num']); ?></small>
                            </td>
                            <td style="font-size:12px; line-height: 1.8;">
                                <?php 
                                if (!empty($app['docs']) && is_array($app['docs'])) {
                                    $docs_list = [];
                                    foreach($app['docs'] as $type => $path) {
                                        if ($type === 'doc_photo') continue; // Hidden from file links list
                                        $filename = basename($path);
                                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                        
                                        $display_name = str_replace('doc_', '', $type);
                                        if (strpos($type, 'doc_qualifications') !== false) $display_name = 'Qualifications';
                                        if ($type === 'doc_insurance') $display_name = 'Insurance';
                                        if ($type === 'doc_calibration') $display_name = 'Calibration';
                                        
                                        $docs_list[] = [
                                            'url' => 'view_doc.php?file=' . urlencode($filename),
                                            'isPdf' => ($ext === 'pdf'),
                                            'title' => addslashes($display_name)
                                        ];
                                    }
                                    $docs_json = htmlspecialchars(json_encode($docs_list), ENT_QUOTES, 'UTF-8');

                                    $idx = 0;
                                    foreach($app['docs'] as $type => $path): 
                                        if ($type === 'doc_photo') continue;
                                        $display_name = $docs_list[$idx]['title'];
                                ?>
                                    <a href="javascript:void(0);" onclick="openDocGallery(<?php echo $docs_json; ?>, <?php echo $idx; ?>)" style="color:#2563eb; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:4px; padding: 2px 0;">
                                        📄 <?php echo $display_name; ?>
                                    </a><br>
                                <?php 
                                        $idx++;
                                    endforeach; 
                                }
                                ?>
                            </td>
                            <td style="font-weight: 800; font-size: 13px; color: <?php echo $status_color; ?>;">
                                <?php echo $status; ?>
                            </td>
                            <td>
                                <?php if ($status === 'Pending'): ?>
                                    <button class="btn btn-accept" onclick="approveApplication('<?php echo $app['id']; ?>')">Approve Member</button>
                                    <button class="btn btn-reject" onclick="rejectApplication('<?php echo $app['id']; ?>')">Reject & Refund</button>
                                <?php else: ?>
                                    <button class="btn btn-delete" onclick="deleteApplication('<?php echo $app['id']; ?>')">Delete App & Files</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($applications)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#6b7280; padding:30px;">No pending applications.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DOCUMENT GALLERY MODAL -->
    <div id="doc-modal" class="modal-overlay" style="z-index: 10000;">
        <div class="modal-box" style="width: 95%; max-width: 1000px; height: 90vh; display: flex; flex-direction: column; padding: 20px;">
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom: 2px solid #f4f7f9; padding-bottom: 10px;">
                <div style="display:flex; align-items:center; gap: 15px; flex-wrap:wrap;">
                    <h2 style="margin:0; color:#1f2937;" id="doc-modal-title">Document Viewer</h2>
                    
                    <div id="doc-nav-controls" style="display:none; align-items:center; gap:10px; background:#f4f7f9; padding:4px 8px; border-radius:6px; border:1px solid #d1d5db;">
                        <button type="button" id="btn-prev-doc" onclick="prevDoc()" style="background:#fff; border:1px solid #d1d5db; border-radius:4px; padding:4px 10px; cursor:pointer; font-weight:bold; font-size:12px; color:#111827;">⬅ Prev</button>
                        <span id="doc-counter" style="font-size:12px; font-weight:bold; color:#4b5563;">1 of X</span>
                        <button type="button" id="btn-next-doc" onclick="nextDoc()" style="background:#fff; border:1px solid #d1d5db; border-radius:4px; padding:4px 10px; cursor:pointer; font-weight:bold; font-size:12px; color:#111827;">Next ➔</button>
                    </div>
                </div>
                <button type="button" onclick="closeDocModal()" style="background:#ef4444; color:#fff; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-weight:bold; text-transform: uppercase;">Close</button>
            </div>
            
            <div id="zoom-controls" style="display:none; margin-bottom:15px; text-align:center;">
                <button type="button" style="background:#1f2937; color:#fff; padding:6px 12px; font-size:12px; font-weight:bold; border:none; border-radius:4px; cursor:pointer;" onclick="zoomDoc(-20)">➖ Zoom Out</button>
                <button type="button" style="background:#e5e7eb; color:#374151; padding:6px 12px; font-size:12px; font-weight:bold; border:none; border-radius:4px; cursor:pointer; margin: 0 5px;" onclick="resetZoom()">🔄 Reset</button>
                <button type="button" style="background:#1f2937; color:#fff; padding:6px 12px; font-size:12px; font-weight:bold; border:none; border-radius:4px; cursor:pointer;" onclick="zoomDoc(20)">➕ Zoom In</button>
            </div>

            <div id="doc-container" style="flex: 1; overflow: auto; border: 1px solid #d1d5db; border-radius: 8px; background:#e5e7eb;"></div>
        </div>
    </div>

    <!-- CTTA EDIT MEMBER MODAL -->
    <div id="edit-modal" class="modal-overlay">
        <div class="modal-box">
            <h2 style="margin-top:0; color:#1f2937;">Edit CTTA Member</h2>
            <form onsubmit="updateCttaMember(event)" enctype="multipart/form-data">
                <input type="hidden" id="edit_m_id">
                <label style="font-size:13px; font-weight:600; color:#4b5563; display:block; margin-top:10px;">Full Name</label>
                <input type="text" id="edit_m_name" required style="width:100%; padding:8px; margin-top:4px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                
                <label style="font-size:13px; font-weight:600; color:#4b5563; display:block; margin-top:10px;">Company Name</label>
                <input type="text" id="edit_m_company" required style="width:100%; padding:8px; margin-top:4px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                
                <label style="font-size:13px; font-weight:600; color:#4b5563; display:block; margin-top:10px;">Qualifications (Comma separated)</label>
                <input type="text" id="edit_m_qual" required style="width:100%; padding:8px; margin-top:4px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                
                <label style="font-size:13px; font-weight:600; color:#4b5563; display:block; margin-top:10px;">Insurance Expiry Date</label>
                <input type="date" id="edit_m_ins" required style="width:100%; padding:8px; margin-top:4px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                
                <label style="font-size:13px; font-weight:600; color:#4b5563; display:block; margin-top:10px;">Calibration Expiry Date</label>
                <input type="date" id="edit_m_cal" required style="width:100%; padding:8px; margin-top:4px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                
                <label style="font-size:13px; font-weight:600; color:#4b5563; display:block; margin-top:10px;">Status</label>
                <select id="edit_m_status" style="width:100%; padding:8px; margin-top:4px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                </select>
                
                <label style="font-size:13px; font-weight:600; color:#4b5563; display:block; margin-top:10px;">Replace Photo (Optional)</label>
                <input type="file" id="edit_m_photo" accept="image/*" style="width:100%; padding:8px; margin-top:4px; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box;">
                
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="submit" class="btn btn-accept" style="flex:1; border:none; border-radius:6px; font-weight:bold; cursor:pointer;">Save Changes</button>
                    <button type="button" class="btn btn-logout" style="flex:1; border:none; border-radius:6px; font-weight:bold; cursor:pointer;" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PRINT CR80 ID CARD MODAL -->
    <div id="card-modal" class="modal-overlay">
        <div class="modal-box" style="max-width:400px; text-align:center;">
            <h2 class="no-print" style="margin-top:0; color:#1f2937; font-size: 18px;">Print CR80 Member ID Card</h2>
            <p class="no-print" style="font-size: 12px; color: #6b7280; margin-bottom: 20px;">Standard Credit Card Size (85.6mm × 53.9mm). Scan QR Code to verify on website.</p>
            
            <div id="print-card-area">
                <div class="cr80-card-wrapper">
                    <div class="cr80-header">
                        <div class="cr80-title">Cron<span>Tech</span> Testers</div>
                        <div class="cr80-badge">MEMBER ID</div>
                    </div>
                    <div class="cr80-body">
                        <img id="card_photo" src="" alt="Photo" class="cr80-photo">
                        <div class="cr80-info">
                            <div id="card_name" class="cr80-name">--</div>
                            <div id="card_comp" class="cr80-comp">--</div>
                            <div id="card_id" class="cr80-id">--</div>
                        </div>
                        <div class="cr80-qr" id="card_qr"></div>
                    </div>
                    <div class="cr80-footer">
                        <span>VERIFIED REGISTER</span>
                        <span>SCAN TO VERIFY</span>
                    </div>
                </div>
            </div>

            <div class="no-print" style="display:flex; gap:10px; margin-top:25px;">
                <button type="button" class="btn btn-accept" style="flex:1; border:none; border-radius:6px; font-weight:bold; cursor:pointer;" onclick="window.print()">🖨️ Print Card</button>
                <button type="button" class="btn btn-logout" style="flex:1; border:none; border-radius:6px; font-weight:bold; cursor:pointer;" onclick="closeCardModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
    // --- DOCUMENT GALLERY LOGIC ---
    let currentZoom = 100;
    let galleryDocs = [];
    let galleryIndex = 0;

    function openDocGallery(docsArray, startIndex) {
        galleryDocs = docsArray;
        galleryIndex = startIndex;
        document.getElementById('doc-modal').style.display = 'flex';
        renderCurrentDoc();
    }

    function renderCurrentDoc() {
        if (galleryDocs.length === 0) return;
        
        const doc = galleryDocs[galleryIndex];
        
        document.getElementById('doc-modal-title').innerText = doc.title;
        document.getElementById('doc-counter').innerText = (galleryIndex + 1) + ' of ' + galleryDocs.length;
        
        const btnPrev = document.getElementById('btn-prev-doc');
        const btnNext = document.getElementById('btn-next-doc');
        
        btnPrev.disabled = (galleryIndex === 0);
        btnPrev.style.opacity = (galleryIndex === 0) ? '0.4' : '1';
        btnPrev.style.cursor = (galleryIndex === 0) ? 'not-allowed' : 'pointer';
        
        btnNext.disabled = (galleryIndex === galleryDocs.length - 1);
        btnNext.style.opacity = (galleryIndex === galleryDocs.length - 1) ? '0.4' : '1';
        btnNext.style.cursor = (galleryIndex === galleryDocs.length - 1) ? 'not-allowed' : 'pointer';

        document.getElementById('doc-nav-controls').style.display = (galleryDocs.length > 1) ? 'flex' : 'none';

        const container = document.getElementById('doc-container');
        const zoomControls = document.getElementById('zoom-controls');
        container.innerHTML = '';
        currentZoom = 100;

        if (doc.isPdf) {
            zoomControls.style.display = 'none';
            container.innerHTML = `<iframe src="${doc.url}" style="width:100%; height:100%; border:none;"></iframe>`;
        } else {
            zoomControls.style.display = 'block';
            container.innerHTML = `
                <div style="padding:20px; min-width:100%; min-height:100%; display:flex; justify-content:center; box-sizing:border-box;">
                    <img id="zoomable-img" src="${doc.url}" style="width:100%; max-width:100%; transition: width 0.15s ease-out;">
                </div>
            `;
        }
    }

    function prevDoc() {
        if (galleryIndex > 0) {
            galleryIndex--;
            renderCurrentDoc();
        }
    }

    function nextDoc() {
        if (galleryIndex < galleryDocs.length - 1) {
            galleryIndex++;
            renderCurrentDoc();
        }
    }

    function closeDocModal() {
        document.getElementById('doc-modal').style.display = 'none';
        document.getElementById('doc-container').innerHTML = ''; 
        galleryDocs = [];
    }

    function zoomDoc(step) {
        const img = document.getElementById('zoomable-img');
        if (!img) return;
        currentZoom += step;
        if (currentZoom < 20) currentZoom = 20;   
        if (currentZoom > 500) currentZoom = 500; 
        img.style.width = currentZoom + '%';
        img.style.maxWidth = 'none';
    }

    function resetZoom() {
        currentZoom = 100;
        const img = document.getElementById('zoomable-img');
        if (img) {
            img.style.width = '100%';
            img.style.maxWidth = '100%';
        }
    }

    // --- STANDARD UI LOGIC ---
    function filterTab(category, btn) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        document.getElementById('view-ctta').style.display = 'none';
        document.getElementById('view-ctta-apps').style.display = 'none';

        if (category === 'ctta') {
            document.getElementById('view-ctta').style.display = 'block';
        } else if (category === 'ctta_apps') {
            document.getElementById('view-ctta-apps').style.display = 'block';
        }
    }

    function approveApplication(id) {
        if(!confirm('Approve this application? This will generate a CTTA ID and add them to the official register.')) return;
        fetch('api_eicr.php?action=approve_application', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id})
        }).then(r => r.json()).then(res => {
            alert(res.message); if(res.success) location.reload();
        });
    }

    function rejectApplication(id) {
        if(!confirm('Reject this application? This will mark it as rejected and attempt to automatically refund their £50 payment.')) return;
        fetch('api_eicr.php?action=reject_application', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id})
        }).then(r => r.json()).then(res => {
            alert(res.message); if(res.success) location.reload();
        });
    }

    function deleteApplication(id) {
        if(!confirm('Permanently delete this application and wipe all uploaded documents? This cannot be undone.')) return;
        fetch('api_eicr.php?action=delete_application', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id})
        }).then(r => r.json()).then(res => {
            alert(res.message); if(res.success) location.reload();
        });
    }

    function addCttaMember(e) {
        e.preventDefault();
        const formData = new FormData();
        formData.append('name', document.getElementById('m_name').value);
        formData.append('company', document.getElementById('m_company').value);
        formData.append('qualifications', document.getElementById('m_qual').value);
        formData.append('insurance_expiry', document.getElementById('m_ins').value);
        formData.append('calibration_expiry', document.getElementById('m_cal').value);
        formData.append('status', document.getElementById('m_status').value);
        
        const photoFile = document.getElementById('m_photo').files[0];
        if (photoFile) { formData.append('photo', photoFile); }

        fetch('api_eicr.php?action=add_member', { method: 'POST', body: formData })
        .then(r => r.json()).then(res => { alert(res.message); if(res.success) location.reload(); });
    }

    function openEditModal(member) {
        document.getElementById('edit_m_id').value = member.id;
        document.getElementById('edit_m_name').value = member.name;
        document.getElementById('edit_m_company').value = member.company;
        document.getElementById('edit_m_qual').value = member.qualifications;
        document.getElementById('edit_m_ins').value = member.insurance_expiry;
        document.getElementById('edit_m_cal').value = member.calibration_expiry;
        document.getElementById('edit_m_status').value = member.status;
        document.getElementById('edit_m_photo').value = '';
        document.getElementById('edit-modal').style.display = 'flex';
    }

    function closeEditModal() { document.getElementById('edit-modal').style.display = 'none'; }

    function updateCttaMember(e) {
        e.preventDefault();
        const formData = new FormData();
        formData.append('id', document.getElementById('edit_m_id').value);
        formData.append('name', document.getElementById('edit_m_name').value);
        formData.append('company', document.getElementById('edit_m_company').value);
        formData.append('qualifications', document.getElementById('edit_m_qual').value);
        formData.append('insurance_expiry', document.getElementById('edit_m_ins').value);
        formData.append('calibration_expiry', document.getElementById('edit_m_cal').value);
        formData.append('status', document.getElementById('edit_m_status').value);
        
        const photoFile = document.getElementById('edit_m_photo').files[0];
        if (photoFile) { formData.append('photo', photoFile); }

        fetch('api_eicr.php?action=edit_member', { method: 'POST', body: formData })
        .then(r => r.json()).then(res => { alert(res.message); if(res.success) location.reload(); });
    }

    function openCardModal(member, photoData) {
        document.getElementById('card_name').innerText = member.name;
        document.getElementById('card_comp').innerText = member.company;
        document.getElementById('card_id').innerText = member.id;
        document.getElementById('card_photo').src = photoData;

        const verifyUrl = `${window.location.protocol}//${window.location.host}/verify.php?query=${encodeURIComponent(member.id)}`;
        const qrContainer = document.getElementById('card_qr');
        qrContainer.innerHTML = '';
        new QRCode(qrContainer, { text: verifyUrl, width: 60, height: 60, colorDark : "#000000", colorLight : "#ffffff", correctLevel : QRCode.CorrectLevel.M });
        document.getElementById('card-modal').style.display = 'flex';
    }

    function closeCardModal() { document.getElementById('card-modal').style.display = 'none'; }

    function deleteCttaMember(id) {
        if(!confirm('Are you sure you want to permanently remove ' + id + '?')) return;
        fetch('api_eicr.php?action=delete_member', {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: id})
        }).then(r => r.json()).then(res => { alert(res.message); if(res.success) location.reload(); });
    }
    </script>
</body>
</html>