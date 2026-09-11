<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTTA | Verify a Tester</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Montserrat', sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #1f2937; }
        .header { background: #1f2937; color: #fff; padding: 40px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 32px; font-weight: 800; letter-spacing: 1px; }
        .header h1 span { color: #ffb703; }
        .header p { margin: 10px 0 0 0; color: #9ca3af; font-size: 15px; }
        
        .container { max-width: 800px; margin: -20px auto 40px auto; padding: 0 20px; }
        .search-box { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); text-align: center; border: 1px solid #e5e7eb;}
        .search-box input { width: 100%; max-width: 400px; padding: 15px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 16px; outline: none; transition: border 0.3s; margin-bottom: 15px; text-align: center;}
        .search-box input:focus { border-color: #ffb703; }
        .search-box button { background: #ffb703; color: #000; border: none; padding: 15px 30px; font-size: 16px; font-weight: 800; border-radius: 8px; cursor: pointer; transition: 0.2s; text-transform: uppercase;}
        .search-box button:hover { background: #fb8500; }

        #results { margin-top: 30px; }
        .member-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: flex; flex-direction: column; gap: 15px;}
        @media(min-width: 600px) { .member-card { flex-direction: row; justify-content: space-between; align-items: center; } }
        
        .mc-info h3 { margin: 0 0 5px 0; font-size: 20px; font-weight: 800; color: #111827; }
        .mc-info p { margin: 0; color: #4b5563; font-size: 14px; font-weight: 600;}
        .mc-id { display: inline-block; background: #f3f4f6; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 800; margin-bottom: 10px; color: #4b5563;}
        
        .mc-status { text-align: center; padding: 15px; border-radius: 8px; font-weight: 800; font-size: 14px; min-width: 200px; border: 2px solid transparent;}
        .status-active { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
        .status-expired { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .mc-status span { display: block; font-size: 11px; font-weight: 600; color: #6b7280; margin-top: 5px; text-transform: none;}
        
        .no-results { text-align: center; padding: 40px; color: #6b7280; font-weight: 600; background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;}
    </style>
</head>
<body>

<div class="header">
    <h1>CT<span>TA</span></h1>
    <p>CronTech Testers Association - Official Verification Register</p>
</div>

<div class="container">
    <div class="search-box">
        <h2 style="margin-top:0;">Verify a Tester</h2>
        <p style="color:#6b7280; font-size:14px; margin-bottom:20px;">Enter a CTTA ID number or Tester Name to check their current compliance status.</p>
        <input type="text" id="search-input" placeholder="e.g. CTTA-001 or John Doe">
        <br>
        <button onclick="searchMember()">Search Register</button>
    </div>

    <div id="results"></div>
</div>

<script>
    function searchMember() {
        const query = document.getElementById('search-input').value;
        const resultsDiv = document.getElementById('results');
        
        if(!query) {
            resultsDiv.innerHTML = '<div class="no-results">Please enter a search term.</div>';
            return;
        }
        
        resultsDiv.innerHTML = '<div class="no-results">Searching...</div>';

        fetch('ctta_api.php?action=verify&query=' + encodeURIComponent(query))
        .then(res => res.json())
        .then(data => {
            if(!data.success || data.data.length === 0) {
                resultsDiv.innerHTML = '<div class="no-results">No registered tester found matching this query. Please check the ID and try again.</div>';
                return;
            }
            
            resultsDiv.innerHTML = '';
            data.data.forEach(m => {
                let is_active = m.status.includes('ACTIVE');
                let statusClass = is_active ? 'status-active' : 'status-expired';
                let icon = is_active ? '✅' : '❌';
                
                resultsDiv.innerHTML += `
                    <div class="member-card">
                        <div class="mc-info">
                            <span class="mc-id">${m.id}</span>
                            <h3>${m.name}</h3>
                            <p>🏢 ${m.company}</p>
                            <p style="margin-top:5px; font-size:12px; color:#6b7280;">🎓 ${m.qualifications}</p>
                        </div>
                        <div class="mc-status ${statusClass}">
                            ${icon} ${m.status}
                            <span>Documents valid until: ${m.valid_until}</span>
                        </div>
                    </div>
                `;
            });
        })
        .catch(err => {
            resultsDiv.innerHTML = '<div class="no-results">Network error. Please try again later.</div>';
        });
    }

    document.getElementById("search-input").addEventListener("keypress", function(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            searchMember();
        }
    });
</script>

</body>
</html>
