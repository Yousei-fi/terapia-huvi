import './style.css';

const initNavigation = () => {
  const navToggle = document.querySelector('[data-nav-toggle]');
  const navPanel = document.querySelector('[data-nav-panel]');

  if (!navToggle || !navPanel) {
    return;
  }

  const closePanel = () => {
    navPanel.classList.add('hidden');
    navToggle.setAttribute('aria-expanded', 'false');
  };

  navToggle.addEventListener('click', () => {
    const isHidden = navPanel.classList.toggle('hidden');
    navToggle.setAttribute('aria-expanded', String(!isHidden));
  });

  navPanel.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', closePanel);
  });

  window.matchMedia('(min-width: 768px)').addEventListener('change', (event) => {
    if (event.matches) {
      closePanel();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePanel();
    }
  });
};

const init = () => {
  initNavigation();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

