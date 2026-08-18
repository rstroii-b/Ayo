import { initHomePage } from './pages/home.js';
import { currentUser, logout } from './auth.js';

const avatar = document.getElementById('avatar');
const hello = document.getElementById('hello');
const user = currentUser();

if (user) {
  avatar.textContent = user.role === 'restaurant_owner' ? 'R' : user.role === 'driver' ? 'L' : 'C';
  avatar.href = '#';
  hello.textContent = 'Connecté';
  avatar.addEventListener('click', (event) => {
    event.preventDefault();
    if (confirm('Se déconnecter ?')) {
      logout();
      window.location.reload();
    }
  });
}

initHomePage();
