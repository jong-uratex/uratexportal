const nodemailer = require('nodemailer');

// Configure the SMTP transporter with your Postfix server details
const transporter = nodemailer.createTransport({
  host: '100.82.205.40', // Your server IP or domain (e.g., dev.uratex.com.ph)
  port: 587,             // Submission port with STARTTLS
  secure: false,         // false for port 587 (uses STARTTLS), true for port 465
  auth: {
    user: 'root', // Replace with your server system user / SASL username
    pass: 'UX@shopify!@#68'  // Replace with the user's password
  },
  tls: {
    rejectUnauthorized: false  // Set to true if using a valid publicly-signed CA certificate
  }
});

async function sendTestEmail() {
  try {
    console.log('Connecting to Postfix SMTP server...');
    
    // Verify connection configuration
    await transporter.verify();
    console.log('SMTP connection and authentication successful!');

    // Send the test email
    const info = await transporter.sendMail({
      from: '"Dev Team" <sender@dev.uratex.com.ph>', // Sender address
      to: 'recipient@example.com',                 // Test recipient email address
      subject: 'Postfix SMTP API Test Mail',         // Subject line
      text: 'Hello! This is a test email sent from our internal Postfix SMTP server.', // Plain text body
      html: '<b>Hello!</b> This is a test email sent from our internal Postfix SMTP server.' // HTML body
    });

    console.log('Email successfully sent! Message ID:', info.messageId);
  } catch (error) {
    console.error('Failed to send email:', error);
  }
}

sendTestEmail();
