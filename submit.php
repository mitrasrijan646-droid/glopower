<?php
/**
 * submit.php
 * Handles the contact form submission with basic spam protection.
 *
 * SPAM PROTECTION LAYERS:
 *   1. Honeypot field ("website") - must be empty
 *   2. Time-trap - form must take at least MIN_SUBMIT_SECONDS to fill in
 *   3. Basic keyword/link filter on the message field
 *   4. Simple rate limiting per IP using a session/flat-file counter
 *   5. Server-side validation of all required fields + email format
 *   6. Header-injection protection (strip CR/LF from user input before
 *      using it in the email "From"/"Reply-To" headers)
 */

header('Content-Type: application/json');

// ---------- CONFIGURATION ----------
$RECIPIENT_EMAILS  = [
    "web@glopower.ae",   // <-- CHANGE THIS
    "ambalika@ermaconsulting.in", // <-- ADD/EDIT additional recipients here
];
$RECIPIENT_NAME    = "Glopower Sales Team";
$SITE_NAME         = "Glopower Website";
$MIN_SUBMIT_SECONDS = 3;    // reject if form submitted faster than this
$MAX_SUBMIT_SECONDS = 3600; // reject stale/replayed submissions after 1 hour
$RATE_LIMIT_SECONDS = 30;   // min seconds between submissions from same IP
$RATE_LIMIT_FILE_DIR = sys_get_temp_dir(); // where to store simple rate-limit markers
// ------------------------------------

function respond($success, $message) {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

// ---------- 1. HONEYPOT CHECK ----------
if (!empty($_POST['website'])) {
    // Silently "succeed" so bots don't learn the honeypot worked
    respond(true, 'Thank you!');
}

// ---------- 2. TIME-TRAP CHECK ----------
$loadedAt = isset($_POST['form_loaded_at']) ? (int) $_POST['form_loaded_at'] : 0;
$now = time();
$elapsed = $now - $loadedAt;

if ($loadedAt <= 0 || $elapsed < $MIN_SUBMIT_SECONDS || $elapsed > $MAX_SUBMIT_SECONDS) {
    respond(false, 'Submission rejected. Please reload the page and try again.');
}

// ---------- 3. SIMPLE PER-IP RATE LIMITING ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = $RATE_LIMIT_FILE_DIR . '/glopower_rl_' . md5($ip) . '.txt';

if (file_exists($rateLimitFile)) {
    $lastSubmit = (int) file_get_contents($rateLimitFile);
    if (($now - $lastSubmit) < $RATE_LIMIT_SECONDS) {
        respond(false, 'You are submitting too quickly. Please wait a moment and try again.');
    }
}

// ---------- HELPER: sanitize a single-line text field ----------
function clean_text($value) {
    $value = trim($value);
    $value = strip_tags($value);
    // strip CR/LF to prevent email header injection if this value is ever used in headers
    $value = str_replace(["\r", "\n"], '', $value);
    return $value;
}

// ---------- 4. GATHER + VALIDATE REQUIRED FIELDS ----------
$required = [
    'interest'       => 'Area of Interest',
    'solution'       => 'Power Solution',
    'purchase_time'  => 'Purchase Timeframe',
    'name'           => 'Name',
    'email'          => 'Email',
    'phone'          => 'Phone Number',
    'company'        => 'Company Name',
    'country'        => 'Country',
    'contact_method' => 'Method of Contact',
    'message'        => 'Message',
];

$data = [];
$missing = [];

foreach ($required as $field => $label) {
    $value = isset($_POST[$field]) ? trim($_POST[$field]) : '';
    if ($value === '') {
        $missing[] = $label;
    }
    $data[$field] = $value;
}

if (!empty($missing)) {
    respond(false, 'Please fill in all required fields: ' . implode(', ', $missing));
}

// Optional fields
$data['state'] = isset($_POST['state']) ? trim($_POST['state']) : '';
$data['city']  = isset($_POST['city']) ? trim($_POST['city']) : '';
$data['page_url'] = isset($_POST['page_url']) ? trim($_POST['page_url']) : '';

// Clean text fields
foreach (['name', 'company', 'phone', 'country', 'state', 'city', 'page_url'] as $f) {
    $data[$f] = clean_text($data[$f]);
}
$data['message'] = trim(strip_tags($_POST['message'])); // keep line breaks in the message body

// ---------- 5. EMAIL VALIDATION ----------
$data['email'] = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.');
}

// ---------- 6. PHONE VALIDATION (loose) ----------
if (!preg_match('/^[0-9+\-\s()]{6,20}$/', $data['phone'])) {
    respond(false, 'Please enter a valid phone number.');
}

// ---------- 7. BASIC CONTENT / SPAM-KEYWORD FILTER ----------
$spamPatterns = [
    '/\bviagra\b/i',
    '/\bcasino\b/i',
    '/\bcrypto\s*invest/i',
    '/\bmake\s*money\s*fast\b/i',
    '/\bloan\s*approved\b/i',
    '/\[url=/i',
    '/<a\s+href/i',
];

// Too many links in the message is a strong spam signal
$linkCount = preg_match_all('/https?:\/\//i', $data['message'], $m);

$isSpam = ($linkCount > 2);
if (!$isSpam) {
    foreach ($spamPatterns as $pattern) {
        if (preg_match($pattern, $data['message']) || preg_match($pattern, $data['name'])) {
            $isSpam = true;
            break;
        }
    }
}

if ($isSpam) {
    // Respond as success to avoid tipping off the bot, but don't send the email
    respond(true, 'Thank you!');
}

// ---------- 8. BUILD + SEND EMAIL ----------
$subject = "New Enquiry from {$SITE_NAME}: {$data['company']}";

$body = "You have received a new enquiry from the website contact form.\n\n";
$body .= "Area of Interest: {$data['interest']}\n";
$body .= "Power Solution: {$data['solution']}\n";
$body .= "Purchase Timeframe: {$data['purchase_time']}\n";
$body .= "Name: {$data['name']}\n";
$body .= "Email: {$data['email']}\n";
$body .= "Phone: {$data['phone']}\n";
$body .= "Company: {$data['company']}\n";
$body .= "Country: {$data['country']}\n";
$body .= "State: {$data['state']}\n";
$body .= "City: {$data['city']}\n";
$body .= "Preferred Contact Method: {$data['contact_method']}\n";
$body .= "Submitted From Page: " . ($data['page_url'] !== '' ? $data['page_url'] : 'N/A') . "\n\n";
$body .= "Message:\n{$data['message']}\n";

// Headers - note the sender's raw email/name are only used after clean_text() stripped CR/LF
$fromName  = clean_text($data['name']);
$fromEmail = $data['email'];

$headers   = [];
$headers[] = "From: {$SITE_NAME} <no-reply@glopower.ae>"; // must be a real/valid domain on this server
$headers[] = "Reply-To: {$fromName} <{$fromEmail}>";
$headers[] = "MIME-Version: 1.0";
$headers[] = "Content-Type: text/plain; charset=UTF-8";
$headers[] = "X-Mailer: PHP/" . phpversion();

$mailSent = mail(
    implode(', ', $RECIPIENT_EMAILS),
    $subject,
    $body,
    implode("\r\n", $headers)
);

// ---------- 9. UPDATE RATE-LIMIT MARKER ----------
@file_put_contents($rateLimitFile, (string) $now);

// ---------- 10. RESPOND ----------
if ($mailSent) {
    respond(true, 'Thank you! Your enquiry has been submitted successfully.');
} else {
    respond(false, 'Sorry, something went wrong while sending your enquiry. Please try again or contact us directly.');
}