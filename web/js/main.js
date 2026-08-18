import { initHomePage } from './pages/home.js';
import { currentUser } from './auth.js';
import { getAddress, promptForAddress } from './address.js';

const avatar = document.getElementById('avatar');
const hello = document.getElementById('hello');
const locationBtn = document.getElementById('location');
const user = currentUser();

if (user) {
  avatar.textContent = (user.first_name?.[0] ?? '?').toUpperCase();
  avatar.href = '/account.html';
  hello.textContent = `Bonjour ${user.first_name}`;
} else {
  avatar.textContent = '?';
  avatar.href = '/login.html';
  hello.textContent = 'Bonjour';
}

function renderAddress() {
  locationBtn.textContent = getAddress() ?? 'Choisir une adresse';
}

locationBtn.addEventListener('click', () => {
  if (promptForAddress() !== null) {
    renderAddress();
  }
});

renderAddress();
initHomePage();
