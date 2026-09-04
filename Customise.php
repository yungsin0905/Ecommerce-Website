<?php include 'include/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

//error report for debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

//verify user login
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);

$coverage_query = "SELECT CITY, POSTCODE, STATE FROM delivery_coverage
WHERE STATUS ='Active' ORDER BY CITY ASC";
$coverage = [];
$result = $conn->query($coverage_query);
while ($row = $result->fetch_assoc()) {
    $coverage[] = $row;
}

$cake_style_query = "SELECT STYLE_NAME FROM cake_style
WHERE STATUS ='Active' ORDER BY STYLE_NAME ASC";

$cake_styles = [];
$result = $conn->query($cake_style_query);
while ($row = $result->fetch_assoc()) {
    $cake_styles[] = $row;
}

//retrieve bakery_info data
$settings_result = $conn->query("SELECT OPEN_DAYS, OPEN_TIME, CLOSE_TIME FROM bakery_info WHERE BAKERY_ID = 1 LIMIT 1");
$settings = $settings_result->fetch_assoc();
$open_time_js  = date('H', strtotime($settings['OPEN_TIME']));  // "10"
$close_time_js = date('H', strtotime($settings['CLOSE_TIME'])); // "20"
$open_days_js  = $settings['OPEN_DAYS']; // "Mon,Tue,Wed,Thu,Fri"

$slots_result = $conn->query("SELECT SLOT_ID, START_TIME, END_TIME FROM delivery_slots WHERE STATUS = 'Active' ORDER BY START_TIME ASC");
$slots = [];
while ($row = $slots_result->fetch_assoc()) {
    $slots[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <style>
       :root
      {
        --main-color: #80b8d2;
        --font-color: #1B2A3C;
        --secondary-color: #F4F8FC;
        --accent-blue: #3c8cb1;
        --rating-color: #F5A623;
        --search-border-color: #C9DCEE;
        --bg-color: #FFFFFF;
        --font2-color: #52708A;
        --card-bg-color: #EBF4FC;
        --btn-hover: #3c8cb1;
        --edit-blue: #2E86DE;
        --delete-red: #E74C3C;
        --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
      }

      body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        color: var(--font-color);
        margin: 0;
        padding: 0;
      }
      
      /* back section */
      .back-section {
        display: flex;
        align-items: center;
        margin: 30px 0 20px 50px;
      }

      .back-link {
        text-decoration: none;
        color: var(--font2-color);
        font-size: 16px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: var(--transition);
      }

      .back-link:hover {
        color: var(--accent-blue);
        text-decoration: none;
      }

      /* page header section */
      .page-header {
        margin-bottom: 30px;
        background-color: var(--bg-color);
        width: 100%;
        text-align: center;
      }

      .page-header h1 {
        font-size: 32px;
        margin: 0 0 10px 0;
        font-weight: 700;
        color: var(--font2-color);
        font-family: 'Poppins', sans-serif;
      }

      .subtitle {
        font-size: 16px;
        margin: 10px 0 25px 0;
        color: var(--font2-color);
        font-family: 'Inter', sans-serif;
      }

      /* main form content */
      .custom-form-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding-bottom: 80px;
      }

      .custom-form-container {
        background: #FFFFFF;
        width: 90%;
        max-width: 820px;
        padding: 45px 50px;
        border-radius: 10px; 
        border: 1px solid var(--search-border-color);
        box-shadow: 0 10px 35px rgba(27, 42, 60, 0.06);
      }

      .form-header h2 {
        color: var(--font-color);
        font-size: 24px;
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        text-align: center;
        margin-bottom: 8px;
      }

      .form-header p {
        text-align: center;
        color: var(--font2-color);
        font-size: 14.5px;
        margin-bottom: 25px;
      }

      .custom-request {
        font-size: 14px; 
        color: var(--font2-color); 
        margin-top: 5px;
      }

      .custom-request a {
        color: var(--accent-blue); 
        font-weight: 600; 
        text-decoration: none;
        transition: var(--transition);
      }

      .custom-request a:hover {
        color: #1B5FB0;
        text-decoration: underline;
      }

      .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr; 
        gap: 22px;
      }

      .form-address-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr; 
        gap: 22px;
      }

      .field-group {
        margin-bottom: 22px;
        text-align: left;
      }

      .field-group label {
        display: block;
        color: var(--font2-color);
        font-weight: 600;
        margin-bottom: 8px;
        font-size: 14px;
        padding-left: 2px;
      }

      .field-group label span {
        color: var(--delete-red);
      }

      .field-group input, 
      .field-group textarea,
      .custom-select {
        width: 100%;
        padding: 12px 18px;
        border: 1px solid var(--search-border-color);
        border-radius: 6px; 
        color: var(--font-color);
        font-size: 14.5px;
        font-family: 'Inter', sans-serif;
        outline: none;
        transition: border-color 0.25s ease, background-color 0.25s ease, box-shadow 0.25s ease;
        box-sizing: border-box;
      }

      .field-group input:focus, 
      .field-group textarea:focus,
      .custom-select:focus {
        border-color: var(--main-color);
        background-color: #FFFFFF;
        box-shadow: 0 0 0 3px rgba(128, 184, 210, 0.2);
      }

      .form-input::placeholder, .hints { color: #7C96AA; }

      .custom-select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2352708A' class='bi bi-chevron-down' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 16px center;
      }

      /* upload picture area */
      .custom-upload-card {
        border: 2px dashed var(--search-border-color);
        border-radius: 16px;
        background: var(--secondary-color);
        text-align: center;
        transition: var(--transition);
        margin-top: 5px;
      }

      .custom-upload-card:hover {
        background: #f0f7fd;
        border-color: var(--main-color);
      }

      .upload-inner {
        display: flex;
        flex-direction: column;
        padding: 30px 20px;
        cursor: pointer;
        color: var(--font2-color);
        font-weight: 500;
        font-size: 14px;
      }

      .upload-inner i { 
        font-size: 36px; 
        margin-bottom: 10px; 
        color: var(--accent-blue);
      }

      /* submit button area */
      .form-footer {
        text-align: center;
        margin-top: 35px;
      }

      .submit-btn {
        background-color: var(--main-color);
        color: #FFFFFF;
        border: none;
        padding: 13px 55px;
        font-size: 16px;
        font-weight: 700;
        font-family: 'Inter', sans-serif;
        border-radius: 6px;
        cursor: pointer;
        transition: var(--transition);
      }

      .submit-btn:hover {
        background-color: var(--font2-color);
      }

      /* word counter */
      .char-counter {
        text-align: right;
        font-size: 12px;
        color: var(--font2-color);
        margin-top: 5px;
        padding-right: 4px;
      }

      .char-counter.over {
        color: var(--delete-red);
        font-weight: 700;
      }

      #date-block-msg {
        margin-top: 8px;
        font-size: 13px;
        color: #e05555;
        background: #fff0f0;
        border: 1px solid #f5c2c2;
        border-radius: 10px;
        padding: 8px 14px;
        display: none;
      }

      @media (max-width: 768px) {
        .custom-form-container {
          padding: 30px 20px;
        }

        .form-grid, .form-address-grid {
          grid-template-columns: 1fr;
          gap: 15px;
        }

        .back-section {
          margin: 20px 0 15px 20px;
        }
      }
    </style>
</head>
<body>
    <?php include 'include/header.php'?>

   <div class="content-container">
        <div class="back-section">
            <a href="index.php" class="back-link">
                <i class="bi bi-chevron-left"></i> Back
            </a>
        </div>

        <div class="custom-form-wrapper">
            <header class="page-header">
                <h1>Custom Inquiries</h1>
                <p class="subtitle">Craft your tailored experience. Every detail customized to your needs</p>
                <p class="custom-request">
                    Already submitted? 
                    <a href="CustomiseRequest.php">
                        Track your request here →
                    </a>
                </p>
            </header>

            <div class="custom-form-container">

                <!-- error display -->
                 <?php if (!empty($_SESSION['form_errors'])):?>
                    <div class="alert alert-danger" style="border-radius:15px;">
                        <ul class="mb-0">
                            <?php foreach ($_SESSION['form_errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php unset($_SESSION['form_errors']); ?>
                <?php endif; ?>

                <!-- success display -->
                <?php if (!empty($_SESSION['form_success'])):?>
                    <div class="alert alert-success" style="border-radius:15px;">
                        <?php echo htmlspecialchars($_SESSION['form_success']); ?>
                    </div>
                    <?php unset($_SESSION['form_success']); ?>
                <?php endif; ?>


                <!-- form area -->
                <form action="submit_custom_request.php" method="POST" enctype="multipart/form-data">
                    
                    <div class="form-grid">
                        <div class="field-group">
                            <label>Recipient Name <span>*</span></label>
                            <input type="text" name="RECIPIENT_NAME" placeholder="Full Name" maxlength = "50"
                            value="<?= htmlspecialchars($old['RECIPIENT_NAME'] ?? '') ?>" required>
                        </div>
                        <div class="field-group">
                            <label>Recipient Email <span>*</span></label>
                            <input type="email" name="RECIPIENT_EMAIL" placeholder="example@mail.com" maxlength = "50"
                            oninput="this.value = this.value.replace(/\s/g, '')"
                            value="<?= htmlspecialchars($old['RECIPIENT_EMAIL'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="field-group">
                        <label>Recipient Phone <span>*</span></label>
                        <div style="display: flex; gap: 10px;">
                            <select name="COUNTRY_CODE" class="custom-select" style="flex: 0 0 100px; padding-right: 10px;">
                                <option value="+60">+60</option>
                            </select>
                            <input type="text" name="PHONE_NUMBER" maxlength="11" placeholder="123456789" 
                                pattern = "\d{9,11}" oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                              value="<?= htmlspecialchars($old['PHONE_NUMBER'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field-group">
                            <label>Delivery Date <span>*</span></label>
                            <input type="date" name="DELIVERY_DATE" id="DELIVERY_DATE"
                            value="<?= htmlspecialchars($old['DELIVERY_DATE'] ?? '') ?>" required>
                            <div id="date-block-msg"></div>
                        </div>

                        <div class="field-group">
                            <label>Delivery Time Slot <span>*</span></label>
                            <select name="SLOT_ID" class="custom-select" required>
                                <option value="" disabled  <?= empty($old['SLOT_ID']) ? 'selected' : '' ?>>Choose a time slot...</option>
                                <?php foreach ($slots as $slot): ?>
                                <option value="<?= $slot['SLOT_ID'] ?>">
                                    <?= date('H:i', strtotime($slot['START_TIME'])) ?> - 
                                    <?= date('H:i', strtotime($slot['END_TIME'])) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-address-grid">
                        <div class="field-group">
                            <label>Cater Count (Pax) <span>*</span></label>
                            <input type="text" name="CATER_COUNT" placeholder="e.g. 20" 
                            oninput="this.value = this.value.replace(/[^0-9]/g, '')" maxlength = "3"
                            value="<?= htmlspecialchars($old['CATER_COUNT'] ?? '') ?>" required>
                        </div>
                        <div class="field-group">
                            <label>Cake Size / Weight</label>
                            <input type="text" name="SIZE" placeholder="e.g. 6 inch / 1kg" maxlength = "10"
                            value="<?= htmlspecialchars($old['SIZE'] ?? '') ?>">
                        </div>
                        <div class="field-group">
                            <label>Quantity <span>*</span></label>
                            <input type="text" name="QUANTITY" placeholder="e.g. 2" maxlength = "2"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                            value="<?= htmlspecialchars($old['QUANTITY'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="field-group">
                        <label>Ideal Cake Flavour</label>
                        <input type="text" name="IDEAL_FLAVOUR" placeholder="e.g. Earl Grey / Belgian Chocolate" 
                        value="<?= htmlspecialchars($old['IDEAL_FLAVOUR'] ?? '') ?>"
                        maxlength="20" id="IDEAL_FLAVOUR">
                        <div class="char-counter"><span id="flavour-count">0</span> / 20</div>
                    </div>

                    <div class="field-group">
                        <label>Address Line <span>*</span></label>
                        <textarea name="RECIPIENT_ADDR" rows="2" maxlength="50" placeholder="Full delivery address"
                        id="RECIPIENT_ADDR" required><?= htmlspecialchars($old['RECIPIENT_ADDR'] ?? '') ?></textarea>
                         <div class="char-counter"><span id="addr-count">0</span> / 50</div>
                    </div>

                    <div class="form-address-grid">
                        <div class="field-group">
                            <label>City <span>*</span></label>
                            <select name="CITY" id="CITY" class="custom-select" required>
                                <option value="" disabled <?= empty($old['CITY']) ? 'selected' : '' ?>>Choose a city...</option>
                                <?php foreach ($coverage as $row): ?>
                                    <option value="<?= htmlspecialchars($row['CITY']) ?>"
                                        data-postcode="<?= htmlspecialchars($row['POSTCODE']) ?>"
                                        data-state="<?= htmlspecialchars($row['STATE']) ?>"
                                        <?= isset($old['CITY']) && $old['CITY'] === $row['CITY'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($row['CITY']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-group">
                            <label>Postcode <span>*</span></label>
                            <input type="text" name="POSTCODE" id="POSTCODE" placeholder="Auto-filled" 
                            value="<?= htmlspecialchars($old['POSTCODE'] ?? '') ?>" readonly>
                        </div>

                        <div class="field-group">
                            <label>State <span>*</span></label>
                            <input type="text" name="STATE" id="STATE" placeholder="Auto-filled" 
                            value="<?= htmlspecialchars($old['STATE'] ?? '') ?>" readonly>
                        </div>
                    </div>

                    <div class="field-group">
                        <label>Custom Design Description <span>*</span></label>
                        <textarea name="CUSTOM_DES" rows="4" maxlength="200" placeholder="Describe themes, colors, or wording..."
                        id ="CUSTOM_DES" required><?= htmlspecialchars($old['CUSTOM_DES'] ?? '') ?></textarea>
                         <div class="char-counter"><span id="des-count">0</span> / 200</div>
                    </div>

                    <div class="form-grid">
                        <div class="field-group">
                            <label>Cake Style (Select one) <span>*</span></label>
                            <select name="CAKE_STYLE" class="custom-select" required>
                                <option value="" disabled <?= empty($old['CAKE_STYLE']) ? 'selected' : '' ?>>Choose a style...</option>
                                <?php foreach ($cake_styles as $row): ?>
                                    <?php $is_selected = isset($old['CAKE_STYLE']) && $old['CAKE_STYLE'] === $row['STYLE_NAME']; ?>
                                        <option value="<?= htmlspecialchars($row['STYLE_NAME']) ?>"
                                        <?= $is_selected ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($row['STYLE_NAME']) ?>
                                        </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field-group">
                            <label>Your Budget <span>*</span></label>
                            <input type="text" step="0.01" name="BUDGET" placeholder="RM 0.00" maxlength = "10"
                             oninput="this.value = this.value.replace(/[^0-9.]/g, '')"
                            value="<?= htmlspecialchars($old['BUDGET'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="field-group">
                        <label>Reference Image (Design)</label>
                        <div class="custom-upload-card">
                            <input type="file" id="REF_IMAGE" name="REF_IMAGE" style="display:none;" accept="image/*">
                            <label for="REF_IMAGE" class="upload-inner">
                                <i class="bi bi-cloud-arrow-up"></i>
                                <span id="file-name">Click to upload reference image</span>
                            </label>
                        </div>
                        <small class="text-muted">Supported formats: JPG, JPEG, PNG. Max size: 5MB.</small>
                    </div>

                    <div class="form-footer">
                        <button type="submit" name="submit_request" class="submit-btn">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php include 'include/footer.php'?>

<script>

//upload image preview and file name display
   document.getElementById('REF_IMAGE').addEventListener('change', function () {
    const fileName = this.files[0] ? this.files[0].name : "Click to upload reference image";
    document.getElementById('file-name').textContent = fileName;
    });
    
    //delivery date must be future date and time
    const openDays    = <?= json_encode(explode(',', $open_days_js)) ?>;
    const dateInput   = document.getElementById('DELIVERY_DATE');
    const dateMsg     = document.getElementById('date-block-msg');
    const dayNames    = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    let blockedDates   = {};
    let blockedDow     = {};
    let availableDates = [];

    //setting newdate min to tomorrow
    const minDate = new Date();
    minDate.setDate(minDate.getDate() + 3);
    dateInput.min = minDate.toISOString().split('T')[0];

    //block and unblock dates based on open days and production capacity
    fetch('get_blocked_dates.php')
    .then(r => r.json())
    .then(data => {
        blockedDates   = data.blocked_dates   || {};
        blockedDow     = data.blocked_dow     || {};
        availableDates = data.available_dates || [];

        dateInput.addEventListener('change', function() {
            const val = this.value;
            if (!val) return;

            const selected = new Date(val + 'T00:00:00');
            const todayStr = new Date().toISOString().slice(0,10);
            const minDateStr = minDate.toISOString().slice(0, 10);
            const dowIndex = selected.getDay();
            const dowName  = dayNames[dowIndex];

            //cannot select today and past date
            if (val <= todayStr) {
                showBlock("Please select a future date (from tomorrow onwards).");
                this.value = '';
                return;
            }

            //non-working day
            if (!openDays.includes(dowName)) {
                showBlock(`We are closed on ${dowName}s. Please choose another day.`);
                this.value = '';
                return;
            }

            //delivery rules by day of week
            if (blockedDow.hasOwnProperty(String(dowIndex))) {
                showBlock(blockedDow[String(dowIndex)]);
                this.value = '';
                return;
            }

            //delivery rules specific date blocked
            if (blockedDates.hasOwnProperty(val)) {
                showBlock(blockedDates[val]);
                this.value = '';
                return;
            }

            //no inside capacity record means fully blocked
            if (!availableDates.includes(val)) {
                showBlock("This date is not available for delivery. Please choose another date.");
                this.value = '';
                return;
            }

            hideBlock();
        });
    });

    function showBlock(msg) {
        dateMsg.textContent = msg;
        dateMsg.style.display = 'block';
    }

    function hideBlock() {
        dateMsg.style.display = 'none';
    }

    //auto-fill postcode and state based on city selection
    document.getElementById('CITY').addEventListener('change', function(){
        const selected = this.options[this.selectedIndex];
        document.getElementById('POSTCODE').value = selected.dataset.postcode ?? '';
        document.getElementById('STATE').value    = selected.dataset.state ?? '';
    });

    //word counter
    function initCounter(textareaId, countId, max) {
        const textarea = document.getElementById(textareaId);
        const counter  = document.getElementById(countId);
        const wrapper  = counter.parentElement;

        counter.textContent = textarea.value.length;

        textarea.addEventListener('input', function () {
            const len = this.value.length;
            counter.textContent = len;
            wrapper.classList.toggle('over', len >= max);
        });
    }

    initCounter('RECIPIENT_ADDR', 'addr-count', 50);
    initCounter('CUSTOM_DES',     'des-count',  200);
    initCounter('IDEAL_FLAVOUR',  'flavour-count', 20);
        
</script>
</body>
</html>