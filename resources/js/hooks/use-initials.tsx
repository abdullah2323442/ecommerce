export function useInitials() {
    const getInitials = (fullName: string): string => {
        // Keep only words that start with a letter or digit, so "Siam (Admin)" gives "SA", not "S(".
        const names = fullName
            .replace(/[^\p{L}\p{N}\s]/gu, ' ')
            .trim()
            .split(/\s+/)
            .filter(Boolean);

        if (names.length === 0) return '';
        if (names.length === 1) return names[0].charAt(0).toUpperCase();

        return `${names[0].charAt(0)}${names[names.length - 1].charAt(0)}`.toUpperCase();
    };

    return getInitials;
}
