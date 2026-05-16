document.addEventListener('DOMContentLoaded', () => {
    const themeToggleBtn = document.getElementById('theme-toggle');
    const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
    
    // Check for saved user preference, if any, on load of the website
    const currentTheme = localStorage.getItem('theme');
    
    if (currentTheme == 'dark') {
        document.body.classList.add('dark-mode');
        if(themeToggleBtn) themeToggleBtn.innerHTML = '☀️';
    } else if (currentTheme == 'light') {
        document.body.classList.remove('dark-mode');
        if(themeToggleBtn) themeToggleBtn.innerHTML = '🌙';
    } else if (prefersDarkScheme.matches) {
        // If no preference, check system preference
        document.body.classList.add('dark-mode');
        if(themeToggleBtn) themeToggleBtn.innerHTML = '☀️';
    } else {
        if(themeToggleBtn) themeToggleBtn.innerHTML = '🌙';
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function() {
            document.body.classList.toggle('dark-mode');
            
            let theme = 'light';
            if (document.body.classList.contains('dark-mode')) {
                theme = 'dark';
                this.innerHTML = '☀️';
            } else {
                this.innerHTML = '🌙';
            }
            localStorage.setItem('theme', theme);
        });
    }
});
