import { useEffect } from 'react';

export function useLandingMotion() {
  useEffect(() => {
    const sections = document.querySelectorAll('.landing .landing-section, .landing .how-section, .landing .landing-cta');
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (preference.matches || !('IntersectionObserver' in window)) return;
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('landing-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08 });
    sections.forEach(section => { section.classList.add('landing-reveal'); observer.observe(section); });
    const showAll = () => sections.forEach(section => section.classList.add('landing-visible'));
    preference.addEventListener('change', showAll);
    return () => {
      observer.disconnect();
      preference.removeEventListener('change', showAll);
      sections.forEach(section => section.classList.remove('landing-reveal', 'landing-visible'));
    };
  }, []);
}
