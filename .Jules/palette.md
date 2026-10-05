## 2024-10-05 - Icon-only buttons lacking ARIA labels
**Learning:** Found an icon-only "✕" close button in `HolidayCalendar.vue` without an `aria-label`. This is an accessibility issue for screen readers.
**Action:** Always add `aria-label` to icon-only buttons like close buttons (e.g. `aria-label="Close dialog"`) to ensure the intent is clear for screen reader users.
