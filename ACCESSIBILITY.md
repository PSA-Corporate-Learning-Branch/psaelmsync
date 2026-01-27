# Accessibility Review Report: PSA ELM Sync Moodle Plugin

**Review Date:** January 2025
**Plugin Path:** `/local/psaelmsync/`
**Standard:** WCAG 2.1 Level AA

## Executive Summary

This accessibility review analyzed the PSA ELM Sync Moodle plugin's user-facing pages against WCAG 2.1 guidelines. Most critical and high-priority issues have been fixed.

## Summary of Findings

| Severity | Count | Status |
|----------|-------|--------|
| Critical | 2 | FIXED |
| High | 6 | 5 FIXED, 1 Acknowledged |
| Medium | 8 | 3 FIXED, 5 Acknowledged |
| Low | 5 | Acknowledged |

### Fixed Issues (January 2025)
- **FIXED:** Table captions added to all data tables (sr-only)
- **FIXED:** Form labels added to search inputs (sr-only labels)
- **FIXED:** Expandable rows now keyboard accessible (tabindex, Enter/Space support)
- **FIXED:** aria-expanded added to expandable row triggers
- **FIXED:** Navigation wrapped in `<nav>` with aria-label
- **FIXED:** aria-current="page" added to active navigation items
- **FIXED:** aria-live="polite" added to dynamic selection counts
- **FIXED:** Chart has figcaption text alternative for screen readers
- **FIXED:** Table headers have scope="col"
- **FIXED:** "Select All" checkbox has aria-label
- **FIXED:** Links opening in new windows have sr-only warning text
- **FIXED:** Focus styles added for keyboard navigation of expandable rows

---

## Detailed Findings

### 1. Form Accessibility

#### Issue 1.1: Missing Label Associations
- **Status:** FIXED
- **Files:** `manual-intake.php`, `manual-complete.php`, `dashboard.php`
- **Fix Applied:** Added visually hidden labels using `.sr-only` class with proper `for`/`id` associations.

#### Issue 1.2: Select Elements Missing Accessible Names
- **Status:** Acknowledged (Low priority)
- **Files:** `manual-intake.php` lines 871-888
- **Description:** Select dropdowns use "All" which could be more descriptive.

#### Issue 1.3: Checkbox Missing Accessible Label
- **Status:** FIXED
- **File:** `manual-intake.php`
- **Fix Applied:** Added `aria-label="Select or deselect all records"`.

---

### 2. Table Accessibility

#### Issue 2.1: Tables Missing Captions
- **Status:** FIXED
- **Files:** All dashboard files, `manual-intake.php`
- **Fix Applied:** Added `<caption class="sr-only">` to all data tables.

#### Issue 2.2: Table Header Scope Missing
- **Status:** FIXED
- **Files:** `dashboard-courses.php`, `dashboard-intake.php`, `manual-intake.php`
- **Fix Applied:** Added `scope="col"` to all `<th>` elements.

#### Issue 2.3: Complex Table Structure
- **Status:** FIXED
- **File:** `manual-intake.php`
- **Fix Applied:** Added `aria-expanded`, `aria-controls`, and `id` attributes to link expandable rows.

---

### 3. Navigation Accessibility

#### Issue 3.1: Tab Navigation Not Using ARIA
- **Status:** FIXED
- **Files:** All files with nav tabs
- **Fix Applied:** Wrapped tabs in `<nav aria-label="PSA ELM Sync sections">`.

#### Issue 3.2: Current Page Not Indicated to Screen Readers
- **Status:** FIXED
- **Files:** All navigation tabs
- **Fix Applied:** Added `aria-current="page"` to active nav links.

---

### 4. Keyboard Accessibility

#### Issue 4.1: Expandable Rows Not Keyboard Accessible
- **Status:** FIXED
- **File:** `manual-intake.php`
- **Fix Applied:** Added `tabindex="0"`, keyboard event handlers for Enter/Space, and `aria-expanded` toggle.

#### Issue 4.2: Bulk Action Buttons Lack Focus Indicator
- **Status:** FIXED
- **File:** `manual-intake.php`
- **Fix Applied:** Added `.record-row:focus` CSS with visible outline.

---

### 5. Color and Contrast

#### Issue 5.1: Color-Only Status Indicators
- **Status:** Partially Fixed
- **Description:** Status badges use color but also include icons and text labels.
- **Fix Applied:** Added `aria-hidden="true"` to decorative icons and sr-only text for status meaning.

#### Issue 5.2: Low Contrast Text
- **Status:** Acknowledged
- **Description:** Bootstrap's `.text-muted` (#6c757d) has 4.68:1 ratio which passes WCAG AA.

#### Issue 5.3: Chart Accessibility
- **Status:** FIXED
- **File:** `dashboard-intake.php`
- **Fix Applied:** Wrapped chart in `<figure>` with `<figcaption>` for screen readers, added `aria-hidden="true"` to canvas.

---

### 6. Screen Reader Support

#### Issue 6.1: Dynamic Content Updates Not Announced
- **Status:** FIXED
- **File:** `manual-intake.php`
- **Fix Applied:** Added `aria-live="polite"` to selection count containers.

#### Issue 6.2: Expand/Collapse State Not Communicated
- **Status:** FIXED
- **File:** `manual-intake.php`
- **Fix Applied:** JavaScript now toggles `aria-expanded` attribute when rows expand/collapse.

---

### 7. Links and Buttons

#### Issue 7.1: Links Opening in New Windows Without Warning
- **Status:** FIXED
- **Files:** Multiple
- **Fix Applied:** Added `<span class="sr-only"> (opens in new window)</span>` to external links.

#### Issue 7.2: Icon-Only Buttons Without Accessible Names
- **Status:** FIXED
- **File:** `manual-intake.php`
- **Fix Applied:** Added sr-only text and aria-label attributes to expandable rows.

#### Issue 7.3: Non-Descriptive Link Text
- **Status:** FIXED for course links
- **Files:** `dashboard-courses.php`
- **Fix Applied:** Added `aria-label` with course name context to "Participants" links.

---

### 8. Page Structure

#### Issue 8.1: Missing Skip Link
- **Status:** Acknowledged
- **Note:** Moodle's theme typically provides skip links. Not plugin's responsibility.

#### Issue 8.2: Missing Landmark Regions
- **Status:** Partially Fixed
- **Fix Applied:** Navigation wrapped in `<nav>`. Main content is typically provided by Moodle theme.

#### Issue 8.3: Heading Hierarchy Issues
- **Status:** Acknowledged
- **Description:** Plugin uses h5/h6 within Moodle's page structure. Moodle provides h1.

---

### 9. Internationalization Concerns

#### Issue 10.1: Hardcoded English Strings
- **Status:** Acknowledged (Low priority)
- **Description:** Some UI strings are hardcoded rather than using `get_string()`.

#### Issue 10.2: Date Formats Hardcoded
- **Status:** Acknowledged (Low priority)
- **Description:** Consider using `userdate()` for localized dates.

---

## Technical Implementation Notes

### CSS Class Added
All files now include the `.sr-only` (screen reader only) class:
```css
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
```

### JavaScript Enhancement
`manual-intake.php` now includes keyboard support for expandable rows:
- Enter and Space keys toggle row expansion
- `aria-expanded` attribute is updated on toggle
- Focus styles ensure keyboard users can see which row is focused

---

## Remaining Recommendations

### Low Priority
1. Move hardcoded strings to language file
2. Use `userdate()` for localized date formatting
3. Add more descriptive option text in select elements (e.g., "All States" instead of "All")
4. Consider adding different line styles to chart for colorblind users

---

## Testing Recommendations

1. **Keyboard Testing:** Navigate all pages using only keyboard (Tab, Shift+Tab, Enter, Space, Arrow keys)
2. **Screen Reader Testing:** Test with NVDA (Windows) or VoiceOver (Mac)
3. **Color Contrast:** Use browser DevTools or WAVE extension to verify contrast ratios
4. **Automated Tools:** Run axe DevTools or WAVE on each page

---

## Conclusion

The PSA ELM Sync plugin now meets most WCAG 2.1 Level AA requirements after the fixes applied. Key improvements include:

- **Full keyboard accessibility** for expandable table rows
- **Proper table semantics** with captions and header scopes
- **Screen reader support** for dynamic content and navigation
- **Form accessibility** with proper labeling

The plugin leverages Moodle's theme for page structure (skip links, main landmarks, h1) and Bootstrap's accessible components for base functionality.
