<?php
/**
 * submit-form.php
 * PHP equivalent of api/submit-form.js — uses the Resend HTTP API via cURL
 * instead of PHP's mail(), so both versions send through the same Resend
 * account/domain and behave identically. Keep field names, validation
 * rules, and email content in sync with api/submit-form.js.
 *
 * SETUP:
 * 1. Get an API key from https://resend.com/api-keys
 * 2. Set it as an environment variable RESEND_API_KEY on your server
 *    (cPanel: MultiPHP INI Editor / .htaccess SetEnv, or edit $RESEND_API_KEY below directly).
 * 3. Verify a sending domain in Resend so FROM_EMAIL isn't flagged as spam.
 */

// ---------------------------------------------------------------------
// CONFIG - keep these in sync with api/submit-form.js
// ---------------------------------------------------------------------
$RESEND_API_KEY = getenv('RESEND_API_KEY') ?: 're_your_api_key_here'; // prefer env var
$RECIPIENT_EMAIL = 'sales@yourdomain.com';
$FROM_EMAIL = 'Glopower Website <no-reply@yourdomain.com>'; // must be on a verified Resend domain
$SITE_NAME = 'Glopower Website';

header('Content-Type: application/json');

// ---------------------------------------------------------------------
// Only allow POST
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ---------------------------------------------------------------------
// Read input - supports both classic form POST and JSON fetch() body,
// same as the JS version accepts a JSON payload.
// ---------------------------------------------------------------------
$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$input = is_array($json) ? $json : $_POST;

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function clean($value) {
    if (!is_string($value)) return '';
    $value = trim($value);
    $value = stripslashes($value);
    return $value;
}

function cleanArray($value) {
    if (empty($value)) return [];
    $arr = is_array($value) ? $value : [$value];
    return array_values(array_filter(array_map('clean', $arr)));
}

function isValidEmail($value) {
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidPhone($value) {
    return preg_match('/^[0-9+\-\s()]{7,20}$/', $value) === 1;
}

function escapeHtml($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function respond($statusCode, $success, $message) {
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// ---------------------------------------------------------------------
// Collect input (mirrors field names in api/submit-form.js)
// ---------------------------------------------------------------------
$areaInterest  = cleanArray($input['area_interest[]'] ?? $input['area_interest'] ?? []);
$powerSolution = cleanArray($input['power_solution[]'] ?? $input['power_solution'] ?? []);
$purchaseTime  = clean($input['purchase_time'] ?? '');
$name          = clean($input['name'] ?? '');
$email         = clean($input['email'] ?? '');
$phone         = clean($input['phone'] ?? '');
$company       = clean($input['company'] ?? '');
$country       = clean($input['country'] ?? '');
$state         = clean($input['state'] ?? '');
$city          = clean($input['city'] ?? '');
$message       = clean($input['message'] ?? '');
$contactMethod = clean($input['contact_method'] ?? '');

// Honeypot
$honeypot = clean($input['website'] ?? '');
if ($honeypot !== '') {
    respond(200, true, 'Thank you for your enquiry.');
}

// ---------------------------------------------------------------------
// Server-side validation (mirrors api/submit-form.js)
// ---------------------------------------------------------------------
$errors = [];

if (empty($areaInterest))              $errors[] = 'Please select at least one area of interest.';
if ($purchaseTime === '')              $errors[] = 'Please select a purchase timeframe.';
if ($name === '')                      $errors[] = 'Name is required.';
if ($email === '' || !isValidEmail($email)) $errors[] = 'A valid email address is required.';
if ($phone === '' || !isValidPhone($phone)) $errors[] = 'A valid phone number is required.';
if ($company === '')                   $errors[] = 'Company name is required.';
if ($country === '')                   $errors[] = 'Country is required.';
if ($message === '')                   $errors[] = 'Message is required.';

if (!empty($errors)) {
    respond(400, false, implode(' ', $errors));
}

// ---------------------------------------------------------------------
// Build the email (mirrors api/submit-form.js)
// ---------------------------------------------------------------------
$subject = "New Enquiry from {$SITE_NAME}: {$name}";

$textBody = implode("\n", [
    "Area of Interest: " . implode(', ', $areaInterest),
    "Power Solution: " . (empty($powerSolution) ? 'N/A' : implode(', ', $powerSolution)),
    "Purchase Timeframe: {$purchaseTime}",
    "",
    "Name: {$name}",
    "Email: {$email}",
    "Phone: {$phone}",
    "Company: {$company}",
    "Country: {$country}",
    "State: " . ($state ?: 'N/A'),
    "City: " . ($city ?: 'N/A'),
    "Preferred Contact Method: " . ($contactMethod ?: 'Not specified'),
    "",
    "Message:",
    $message,
]);

$htmlBody = "
    <h2>New Enquiry from " . escapeHtml($SITE_NAME) . "</h2>
    <p><strong>Area of Interest:</strong> " . escapeHtml(implode(', ', $areaInterest)) . "</p>
    <p><strong>Power Solution:</strong> " . escapeHtml(empty($powerSolution) ? 'N/A' : implode(', ', $powerSolution)) . "</p>
    <p><strong>Purchase Timeframe:</strong> " . escapeHtml($purchaseTime) . "</p>
    <hr>
    <p><strong>Name:</strong> " . escapeHtml($name) . "</p>
    <p><strong>Email:</strong> " . escapeHtml($email) . "</p>
    <p><strong>Phone:</strong> " . escapeHtml($phone) . "</p>
    <p><strong>Company:</strong> " . escapeHtml($company) . "</p>
    <p><strong>Country:</strong> " . escapeHtml($country) . "</p>
    <p><strong>State:</strong> " . escapeHtml($state ?: 'N/A') . "</p>
    <p><strong>City:</strong> " . escapeHtml($city ?: 'N/A') . "</p>
    <p><strong>Preferred Contact Method:</strong> " . escapeHtml($contactMethod ?: 'Not specified') . "</p>
    <hr>
    <p><strong>Message:</strong><br>" . nl2br(escapeHtml($message)) . "</p>
";

// ---------------------------------------------------------------------
// Send via Resend HTTP API (cURL) - same provider as the Vercel version
// ---------------------------------------------------------------------
$payload = json_encode([
    'from'     => $FROM_EMAIL,
    'to'       => [$RECIPIENT_EMAIL],
    'reply_to' => "{$name} <{$email}>",
    'subject'  => $subject,
    'text'     => $textBody,
    'html'     => $htmlBody,
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $RESEND_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || $httpCode >= 400) {
    error_log('Resend API error: ' . $curlError . ' | HTTP ' . $httpCode . ' | ' . $response);
    respond(502, false, 'Failed to send email. Please try again later.');
}

respond(200, true, 'Thank you for your enquiry. We will be in touch shortly.');
