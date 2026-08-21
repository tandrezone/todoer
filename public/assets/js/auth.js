// Sign-in / join tab switching.
//
// This was an inline <script> in the old login.php. Moving it into a file means the sign-in page
// carries no executable markup, which is one of the two things standing between this app and a
// Content-Security-Policy that forbids inline script.
document.querySelectorAll('.tab-btn').forEach(button => {
  button.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(other => other.classList.remove('active'));
    button.classList.add('active');

    const isRegister = button.dataset.mode === 'register';
    document.getElementById('mode-field').value = button.dataset.mode;
    // The invite code only means anything when creating an account.
    document.getElementById('invite-field').hidden = !isRegister;
    document.getElementById('submit-btn').textContent = isRegister ? 'Create account & join' : 'Log in';
  });
});
