// api/submit-form.js
// Vercel Serverless Function - handles the Glopower contact form using Resend.
//
// SETUP:
// 1. npm install resend  (run this in your project root; creates/updates package.json)
// 2. In Vercel dashboard -> Project -> Settings -> Environment Variables, add:
//      RESEND_API_KEY = re_xxxxxxxxxxxx   (get this from https://resend.com/api-keys)
// 3. Verify a sending domain in Resend (Domains tab) so "From" isn't flagged as spam.
//    Until you verify a domain, Resend lets you send from "onboarding@resend.dev"
//    for testing only.
// 4. Deploy. Vercel will expose this file automatically at: /api/submit-form

import { Resend } from 'resend';

const resend = new Resend(process.env.RESEND_API_KEY);

// ---------------------------------------------------------------------
// CONFIG - edit these
// ---------------------------------------------------------------------
const RECIPIENT_EMAIL = 'mitra.srijan@gmail.com';       // where enquiries land
const FROM_EMAIL = 'Glopower Website <no-reply@yourdomain.com>'; // must be on a verified Resend domain
const SITE_NAME = 'Glopower Website Enquiry Form';

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function clean(value) {
  if (typeof value !== 'string') return '';
  return value.trim();
}

function cleanArray(value) {
  if (!value) return [];
  const arr = Array.isArray(value) ? value : [value];
  return arr.map(clean).filter(Boolean);
}

function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function isValidPhone(value) {
  return /^[0-9+\-\s()]{7,20}$/.test(value);
}

function escapeHtml(str) {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');
    return res.status(405).json({ success: false, message: 'Method not allowed.' });
  }

  const body = req.body || {};

  // Honeypot - add <input type="text" name="website" style="display:none"> to your form
  if (clean(body.website)) {
    // Pretend success to bots
    return res.status(200).json({ success: true, message: 'Thank you for your enquiry.' });
  }

  const areaInterest = cleanArray(body['area_interest[]'] ?? body.area_interest);
  const powerSolution = cleanArray(body['power_solution[]'] ?? body.power_solution);
  const purchaseTime = clean(body.purchase_time);
  const name = clean(body.name);
  const email = clean(body.email);
  const phone = clean(body.phone);
  const company = clean(body.company);
  const country = clean(body.country);
  const state = clean(body.state);
  const city = clean(body.city);
  const message = clean(body.message);
  const contactMethod = clean(body.contact_method);

  // ---------------------------------------------------------------------
  // Server-side validation (mirrors the client JS - never trust the client)
  // ---------------------------------------------------------------------
  const errors = [];

  if (areaInterest.length === 0) errors.push('Please select at least one area of interest.');
  if (!purchaseTime) errors.push('Please select a purchase timeframe.');
  if (!name) errors.push('Name is required.');
  if (!email || !isValidEmail(email)) errors.push('A valid email address is required.');
  if (!phone || !isValidPhone(phone)) errors.push('A valid phone number is required.');
  if (!company) errors.push('Company name is required.');
  if (!country) errors.push('Country is required.');
  if (!message) errors.push('Message is required.');

  if (errors.length > 0) {
    return res.status(400).json({ success: false, message: errors.join(' ') });
  }

  // ---------------------------------------------------------------------
  // Build + send the email
  // ---------------------------------------------------------------------
  const subject = `New Enquiry from ${SITE_NAME}: ${name}`;

  const textBody = [
    `Area of Interest: ${areaInterest.join(', ')}`,
    `Power Solution: ${powerSolution.length ? powerSolution.join(', ') : 'N/A'}`,
    `Purchase Timeframe: ${purchaseTime}`,
    '',
    `Name: ${name}`,
    `Email: ${email}`,
    `Phone: ${phone}`,
    `Company: ${company}`,
    `Country: ${country}`,
    `State: ${state || 'N/A'}`,
    `City: ${city || 'N/A'}`,
    `Preferred Contact Method: ${contactMethod || 'Not specified'}`,
    '',
    'Message:',
    message,
  ].join('\n');

  const htmlBody = `
    <h2>New Enquiry from ${escapeHtml(SITE_NAME)}</h2>
    <p><strong>Area of Interest:</strong> ${escapeHtml(areaInterest.join(', '))}</p>
    <p><strong>Power Solution:</strong> ${escapeHtml(powerSolution.length ? powerSolution.join(', ') : 'N/A')}</p>
    <p><strong>Purchase Timeframe:</strong> ${escapeHtml(purchaseTime)}</p>
    <hr>
    <p><strong>Name:</strong> ${escapeHtml(name)}</p>
    <p><strong>Email:</strong> ${escapeHtml(email)}</p>
    <p><strong>Phone:</strong> ${escapeHtml(phone)}</p>
    <p><strong>Company:</strong> ${escapeHtml(company)}</p>
    <p><strong>Country:</strong> ${escapeHtml(country)}</p>
    <p><strong>State:</strong> ${escapeHtml(state || 'N/A')}</p>
    <p><strong>City:</strong> ${escapeHtml(city || 'N/A')}</p>
    <p><strong>Preferred Contact Method:</strong> ${escapeHtml(contactMethod || 'Not specified')}</p>
    <hr>
    <p><strong>Message:</strong><br>${escapeHtml(message).replace(/\n/g, '<br>')}</p>
  `;

  try {
    const { error } = await resend.emails.send({
      from: FROM_EMAIL,
      to: RECIPIENT_EMAIL,
      replyTo: `${name} <${email}>`,
      subject,
      text: textBody,
      html: htmlBody,
    });

    if (error) {
      console.error('Resend error:', error);
      return res.status(502).json({ success: false, message: 'Failed to send email. Please try again later.' });
    }

    return res.status(200).json({
      success: true,
      message: 'Thank you for your enquiry. We will be in touch shortly.',
    });
  } catch (err) {
    console.error('Unexpected error:', err);
    return res.status(500).json({ success: false, message: 'Something went wrong. Please try again later.' });
  }
}
