<?php
// footer.php - Close the card and body
?>
    </div>
    <script>
        // Shared closePopup function for calendar
        function closePopup() {
            if (document.getElementById('eventPopup')) {
                document.getElementById('eventPopup').style.display = 'none';
                document.getElementById('eventPopupOverlay').style.display = 'none';
                document.getElementById('eventForm').reset();
                document.getElementById('popupTitle').textContent = 'Add Event';
                document.getElementById('eventId').value = '';
                document.getElementById('allDay').checked = false;
                document.getElementById('startTime').disabled = false;
                document.getElementById('endTime').disabled = false;
                document.getElementById('saveButton').setAttribute('name', 'add_event');
            }
        }
        // Weather auto-refresh
        if (window.location.pathname.includes('weather.php')) {
            setTimeout(function() { location.reload(); }, 600000);
            document.querySelector('.weather-card').addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.transition = 'transform 0.3s ease';
            });
            document.querySelector('.weather-card').addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        }
    </script>
</body>
</html>