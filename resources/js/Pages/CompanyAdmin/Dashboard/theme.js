import { ref } from 'vue';

const STORAGE_KEY = 'mealbells-theme';

// Read from the attribute rather than storage: the inline script in
// app.blade.php has already resolved stored choice versus system preference,
// and repeating that here could disagree with what was painted.
export const theme = ref(document.documentElement.dataset.theme === 'light' ? 'light' : 'dark');

export function setTheme(next) {
    theme.value = next;
    document.documentElement.dataset.theme = next;

    try {
        localStorage.setItem(STORAGE_KEY, next);
    } catch {
        // A private window can refuse storage; the switch still works for this visit.
    }
}
