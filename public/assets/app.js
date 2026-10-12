const TOKEN_KEY = "qa_task_token";
const PATH_NOTES = "/account/notes";
const PATH_PROFILE = "/account/profile";
const PATH_SHARED = "/account/shared";
const PATH_TAGS = "/account/tags";
const PATH_TASKS = "/account/tasks";
const PATH_AUDIT = "/account/audit";
const MAX_TAGS_PER_NOTE = 10;
const ROLE_LABELS = {owner: "Owner", editor: "Editor", viewer: "Viewer"};

const authSection = document.getElementById("auth-section");
const accountSection = document.getElementById("account-section");
const notesView = document.getElementById("notes-view");
const profileView = document.getElementById("profile-view");
const accountTitle = document.getElementById("account-title");
const notesListEl = document.getElementById("notes-list");
const statusEl = document.getElementById("status");
const profileEmailEl = document.getElementById("profile-email");
const profileIdEl = document.getElementById("profile-id");
const searchQueryEl = document.getElementById("notes-search-query");
const searchFieldEl = document.getElementById("notes-search-field");
const searchSortEl = document.getElementById("notes-search-sort");
const pageSizeEl = document.getElementById("notes-page-size");
const prevPageEl = document.getElementById("notes-prev-page");
const nextPageEl = document.getElementById("notes-next-page");
const pageInfoEl = document.getElementById("notes-page-info");
const listTotalEl = document.getElementById("notes-list-total");
const signupFormEl = document.getElementById("signup-form");
const signinFormEl = document.getElementById("signin-form");
const createNoteFormEl = document.getElementById("create-note-form");
const logoutButtonEl = document.getElementById("logout-button");
const navNotesButtonEl = document.getElementById("nav-notes-button");
const navProfileButtonEl = document.getElementById("nav-profile-button");
const navSharedButtonEl = document.getElementById("nav-shared-button");
const navTagsButtonEl = document.getElementById("nav-tags-button");
const taxonomyView = document.getElementById("taxonomy-view");
const navTasksButtonEl = document.getElementById("nav-tasks-button");
const navAuditButtonEl = document.getElementById("nav-audit-button");
const auditView = document.getElementById("audit-view");
const tasksView = document.getElementById("tasks-view");
const tasksBoardEl = document.getElementById("tasks-board");
const tasksFeedbackEl = document.getElementById("tasks-feedback");
const tagFilterEl = document.getElementById("notes-tag-filter");
const tagFilterLabelEl = document.getElementById("notes-tag-filter-label");
const updatedFromEl = document.getElementById("notes-updated-from");
const updatedToEl = document.getElementById("notes-updated-to");
const activeFiltersEl = document.getElementById("active-filters");
const activeFilterChipsEl = document.getElementById("active-filter-chips");
const resetFiltersEl = document.getElementById("notes-reset-filters");
const bulkSelectPageEl = document.getElementById("bulk-select-page");
const bulkCountEl = document.getElementById("bulk-count");
const bulkActionsEl = document.getElementById("bulk-actions");
const bulkCategoryEl = document.getElementById("bulk-category");
const bulkTagEl = document.getElementById("bulk-tag");
const bulkReportEl = document.getElementById("bulk-report");
const notificationsButtonEl = document.getElementById("notifications-button");
const notificationsBadgeEl = document.getElementById("notifications-badge");
const notificationPreferencesFormEl = document.getElementById("notification-preferences-form");
const notificationPreferencesFeedbackEl = document.getElementById("notification-preferences-feedback");
const forgotButtonEl = document.getElementById("forgot-password-button");
const forgotCardEl = document.getElementById("forgot-password-card");
const forgotFormEl = document.getElementById("forgot-password-form");
const forgotFeedbackEl = document.getElementById("forgot-feedback");
const resetCardEl = document.getElementById("reset-password-card");
const resetFormEl = document.getElementById("reset-password-form");
const resetFeedbackEl = document.getElementById("reset-feedback");
const resetRequestNewEl = document.getElementById("reset-request-new");
const profilePendingEl = document.getElementById("profile-pending");
const changeEmailFormEl = document.getElementById("change-email-form");
const changeEmailFeedbackEl = document.getElementById("change-email-feedback");
const settingsFormEl = document.getElementById("settings-form");
const settingsTimezoneEl = document.getElementById("settings-timezone");
const settingsFeedbackEl = document.getElementById("settings-feedback");
const changePasswordButtonEl = document.getElementById("change-password-button");
const notesDashboardEl = document.getElementById("notes-dashboard");
const createNoteSectionEl = document.getElementById("create-note-section");
const notesListHeadingEl = document.getElementById("notes-list-heading");
const searchNotesFormEl = document.getElementById("search-notes-form");

let modalCounter = 0;

const appDomReady = [
    authSection,
    accountSection,
    notesView,
    profileView,
    accountTitle,
    notesListEl,
    statusEl,
    profileEmailEl,
    profileIdEl,
    searchQueryEl,
    searchFieldEl,
    searchSortEl,
    pageSizeEl,
    prevPageEl,
    nextPageEl,
    pageInfoEl,
    listTotalEl,
    signupFormEl,
    signinFormEl,
    createNoteFormEl,
    logoutButtonEl,
    navNotesButtonEl,
    navProfileButtonEl,
    navSharedButtonEl,
    navTagsButtonEl,
    taxonomyView,
    navTasksButtonEl,
    navAuditButtonEl,
    auditView,
    tasksView,
    tasksBoardEl,
    tasksFeedbackEl,
    tagFilterEl,
    tagFilterLabelEl,
    updatedFromEl,
    updatedToEl,
    activeFiltersEl,
    activeFilterChipsEl,
    resetFiltersEl,
    notificationsButtonEl,
    notificationsBadgeEl,
    notificationPreferencesFormEl,
    notificationPreferencesFeedbackEl,
    bulkSelectPageEl,
    bulkCountEl,
    bulkActionsEl,
    bulkCategoryEl,
    bulkTagEl,
    bulkReportEl,
    forgotButtonEl,
    forgotCardEl,
    forgotFormEl,
    forgotFeedbackEl,
    resetCardEl,
    resetFormEl,
    resetFeedbackEl,
    resetRequestNewEl,
    profilePendingEl,
    changeEmailFormEl,
    changeEmailFeedbackEl,
    settingsFormEl,
    settingsTimezoneEl,
    settingsFeedbackEl,
    changePasswordButtonEl,
    notesDashboardEl,
    createNoteSectionEl,
    notesListHeadingEl,
    searchNotesFormEl,
].every(Boolean);

let notesCache = [];
let accountProfile = null;
let currentPage = 1;
let totalItems = 0;
let totalPages = 1;
let hasNextPage = false;
let hasPrevPage = false;
let notesRequestId = 0;
let userTimezone = "UTC";
let pendingResetToken = "";
// Selected notes (ID -> title). The selection survives paging, searching and reloading the list.
const selectedNotes = new Map();
const BULK_MAX_IDS = 100;

function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

function setToken(token) {
    localStorage.setItem(TOKEN_KEY, token);
}

function clearToken() {
    localStorage.removeItem(TOKEN_KEY);
}

function setStatus(message, isError = false, allowHtml = false) {
    if (allowHtml) {
        statusEl.innerHTML = message;
    } else {
        statusEl.textContent = message;
    }

    statusEl.classList.remove("status--ok", "status--error");
    if (message) {
        statusEl.classList.add(isError ? "status--error" : "status--ok");
    }
}

function currentPath() {
    const path = window.location.pathname.replace(/\/+$/, "");
    return path === "" ? "/" : path;
}

function navigateTo(path, replace = false) {
    if (replace) {
        window.history.replaceState({}, "", path);
    } else if (currentPath() !== path) {
        window.history.pushState({}, "", path);
    }
}

function clearEmailConfirmationParamsFromUrl() {
    const url = new URL(window.location.href);
    url.searchParams.delete("confirm_email");
    url.searchParams.delete("confirm_code");
    url.searchParams.delete("reset_token");
    url.searchParams.delete("email_change_token");
    const query = url.searchParams.toString();
    const nextUrl = `${url.pathname}${query ? `?${query}` : ""}${url.hash}`;
    window.history.replaceState({}, "", nextUrl);
}

async function api(path, options = {}) {
    const headers = options.headers ? {...options.headers} : {};
    if (!("Accept" in headers) && !("accept" in headers)) {
        headers["Accept"] = "application/json";
    }
    if (!(options.body instanceof FormData)) {
        headers["Content-Type"] = "application/json";
    }

    // Public calls (reset and confirmation links) must work whatever state the stored session is in.
    const token = options.anonymous ? null : getToken();
    if (token) {
        headers["Authorization"] = `Bearer ${token}`;
    }

    const response = await fetch(path, {...options, headers});
    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));

        // The session ended on the server: expired, or the password or email was changed elsewhere.
        if (response.status === 401 && token && getToken() === token) {
            signOutLocally("Your session has ended. Sign in again.", true);
            throw apiError("Your session has ended. Sign in again.", 401);
        }
        // Requests still queued behind the one that ended the session carry no token any more.
        if (response.status === 401 && !token && !options.anonymous && !path.startsWith("/api/auth/sign")) {
            throw apiError("Your session has ended. Sign in again.", 401);
        }

        if (response.status >= 500) {
            throw apiError("500", response.status);
        }

        const validationMessage = extractValidationMessage(payload);
        if (validationMessage) {
            throw apiError(validationMessage, response.status);
        }

        throw apiError(
            payload.error
            || payload.message
            || payload.detail
            || payload["hydra:description"]
            || `Request failed: ${response.status}`,
            response.status,
        );
    }

    const contentType = response.headers.get("Content-Type") || "";
    if (!contentType.includes("application/json")) {
        return response;
    }

    const data = await response.json();

    return options.withHeaders ? {data, headers: response.headers} : data;
}

function apiError(message, status) {
    const error = new Error(message);
    error.status = status;
    return error;
}

function isAccessLost(error) {
    return error?.status === 403 || error?.status === 404;
}

function extractValidationMessage(payload) {
    if (!payload || typeof payload !== "object") {
        return "";
    }

    const violations = Array.isArray(payload.violations) ? payload.violations : [];
    if (violations.length > 0) {
        return violations
            .map((violation) => {
                const field = typeof violation?.propertyPath === "string" ? violation.propertyPath.trim() : "";
                const message = typeof violation?.message === "string" ? violation.message.trim() : "";
                if (!message) {
                    return "";
                }

                return field ? `${field}: ${message}` : message;
            })
            .filter(Boolean)
            .join("; ");
    }

    const hydraDescription = typeof payload["hydra:description"] === "string"
        ? payload["hydra:description"].trim()
        : "";

    if (
        hydraDescription
        && (
            payload["hydra:title"] === "An error occurred"
            || hydraDescription.toLowerCase().includes("validation")
        )
    ) {
        return hydraDescription;
    }

    return "";
}

function showAuthUI() {
    accountSection.classList.add("hidden");
    authSection.classList.remove("hidden");
}

function accountPathFor(path) {
    return [PATH_PROFILE, PATH_SHARED, PATH_TAGS, PATH_TASKS, PATH_AUDIT].includes(path) ? path : PATH_NOTES;
}

function isSharedView() {
    return currentPath() === PATH_SHARED;
}

function isNotesListVisible() {
    return !accountSection.classList.contains("hidden") && !notesView.classList.contains("hidden");
}

function setActiveNav(path) {
    const buttons = [
        [navNotesButtonEl, PATH_NOTES],
        [navSharedButtonEl, PATH_SHARED],
        [navTagsButtonEl, PATH_TAGS],
        [navTasksButtonEl, PATH_TASKS],
        [navAuditButtonEl, PATH_AUDIT],
        [navProfileButtonEl, PATH_PROFILE],
    ];
    for (const [button, buttonPath] of buttons) {
        const isActive = accountPathFor(path) === buttonPath;
        button.classList.toggle("active-nav", isActive);
        if (isActive) {
            button.setAttribute("aria-current", "page");
        } else {
            button.removeAttribute("aria-current");
        }
    }
}

function showAccountView(path) {
    authSection.classList.add("hidden");
    accountSection.classList.remove("hidden");
    // Moving around the app is a good moment to bring the bell up to date.
    refreshUnreadNotifications();

    const isProfile = path === PATH_PROFILE;
    const isShared = path === PATH_SHARED;
    const isTags = path === PATH_TAGS;
    const isTasks = path === PATH_TASKS;
    const isAudit = path === PATH_AUDIT;
    tasksView.classList.toggle("hidden", !isTasks);
    auditView.classList.toggle("hidden", !isAudit);
    notesView.classList.toggle("hidden", isProfile || isTags || isTasks || isAudit);
    profileView.classList.toggle("hidden", !isProfile);
    taxonomyView.classList.toggle("hidden", !isTags);
    createNoteSectionEl.classList.toggle("hidden", isShared);
    // The tag filter offers the signed-in user's own tags; shared notes carry their owner's.
    tagFilterLabelEl.classList.toggle("hidden", isShared);
    notesDashboardEl.classList.toggle("dashboard-grid--single", isShared);
    notesListHeadingEl.textContent = isShared ? "Shared with me" : "List";
    accountTitle.textContent = {[PATH_PROFILE]: "Profile", [PATH_SHARED]: "Shared with me", [PATH_TAGS]: "Tags and categories", [PATH_TASKS]: "Tasks", [PATH_AUDIT]: "Audit"}[path] || "Notes";
    setActiveNav(path);
}

function escapeHtml(value) {
    return value
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function buildModalShell(titleText) {
    const backdrop = document.createElement("div");
    backdrop.className = "modal-backdrop";

    const dialog = document.createElement("div");
    dialog.className = "modal-dialog panel";
    dialog.setAttribute("role", "dialog");
    dialog.setAttribute("aria-modal", "true");

    const title = document.createElement("h3");
    title.className = "modal-title";
    title.id = `modal-title-${++modalCounter}`;
    title.textContent = titleText;
    dialog.setAttribute("aria-labelledby", title.id);

    const content = document.createElement("div");
    content.className = "modal-content";

    dialog.appendChild(title);
    dialog.appendChild(content);
    backdrop.appendChild(dialog);

    return {backdrop, content};
}

// Keeps keyboard focus inside the dialog, closes it on Escape and gives focus back on close.
function activateDialog(backdrop, onDismiss) {
    const previouslyFocused = document.activeElement;
    const focusable = () => Array.from(
        backdrop.querySelectorAll("button, input, select, textarea, a[href]"),
    ).filter((element) => !element.disabled);

    const onKeydown = (event) => {
        if (backdrop !== document.body.lastElementChild) {
            return;
        }

        if (event.key === "Escape") {
            event.preventDefault();
            onDismiss();
            return;
        }

        if (event.key !== "Tab") {
            return;
        }

        const elements = focusable();
        if (elements.length === 0) {
            event.preventDefault();
            return;
        }

        const first = elements[0];
        const last = elements[elements.length - 1];
        if (!backdrop.contains(document.activeElement)) {
            event.preventDefault();
            first.focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    document.addEventListener("keydown", onKeydown);

    return () => {
        document.removeEventListener("keydown", onKeydown);
        if (previouslyFocused instanceof HTMLElement && document.contains(previouslyFocused)) {
            previouslyFocused.focus();
        }
    };
}

function openConfirmModal({title, message, confirmLabel}) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell(title);

        const body = document.createElement("div");
        body.className = "modal-form";
        body.innerHTML = `
            <p class="modal-message"></p>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="cancel">Cancel</button>
                <button type="button" class="danger" data-action="confirm"></button>
            </div>
        `;
        body.querySelector(".modal-message").textContent = message;
        body.querySelector('[data-action="confirm"]').textContent = confirmLabel;

        let deactivate = () => {};
        const close = (result) => {
            backdrop.remove();
            deactivate();
            resolve(result);
        };

        body.querySelector('[data-action="cancel"]').addEventListener("click", () => close(false));
        body.querySelector('[data-action="confirm"]').addEventListener("click", () => close(true));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(false);
            }
        });

        content.appendChild(body);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, () => close(false));
        body.querySelector('[data-action="cancel"]').focus();
    });
}

function openShareDialog(note) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Share note");
        const sharesUrl = `/api/notes/${note.id}/shares`;

        const body = document.createElement("div");
        body.className = "modal-form share-dialog";
        body.innerHTML = `
            <p class="modal-message share-note-title"></p>
            <form class="share-form" novalidate>
                <label>Email of a registered user
                    <input type="email" name="email" maxlength="190" autocomplete="off" required aria-describedby="share-feedback">
                </label>
                <label>Role
                    <select name="role">
                        <option value="viewer">Viewer (read-only)</option>
                        <option value="editor">Editor (can edit)</option>
                    </select>
                </label>
                <button type="submit">Share</button>
            </form>
            <p id="share-feedback" class="share-feedback" role="alert"></p>
            <h4 class="share-heading">People with access</h4>
            <div class="share-list" aria-live="polite"></div>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="close">Close</button>
            </div>
        `;
        body.querySelector(".share-note-title").textContent = note.title || "";

        const form = body.querySelector(".share-form");
        const emailInput = form.querySelector('input[name="email"]');
        const roleSelect = form.querySelector('select[name="role"]');
        const feedbackEl = body.querySelector(".share-feedback");
        const listEl = body.querySelector(".share-list");

        let closed = false;
        let deactivate = () => {};
        const close = () => {
            if (closed) {
                return;
            }
            closed = true;
            backdrop.remove();
            deactivate();
            resolve();
        };

        const setFeedback = (message, isError = false) => {
            feedbackEl.textContent = message;
            feedbackEl.classList.toggle("share-feedback--error", Boolean(message) && isError);
            feedbackEl.classList.toggle("share-feedback--ok", Boolean(message) && !isError);
            emailInput.setAttribute("aria-invalid", message && isError ? "true" : "false");
        };

        const setBusy = (busy) => {
            for (const control of body.querySelectorAll("form button, form input, form select, .share-list select, .share-list button")) {
                control.disabled = busy;
            }
            listEl.setAttribute("aria-busy", busy ? "true" : "false");
        };

        // The note is gone or no longer ours: nothing left to manage here.
        const closeIfAccessLost = (error) => {
            if (error?.status !== 403 && !(error?.status === 404 && error.message === "Note not found.")) {
                return false;
            }
            close();
            setStatus("You can no longer manage sharing for this note.", true);
            refreshNotes().catch(() => {});
            return true;
        };

        const renderShares = (shares) => {
            listEl.innerHTML = "";
            if (shares.length === 0) {
                listEl.innerHTML = `<p class="share-empty">Not shared with anyone yet.</p>`;
                return;
            }

            for (const share of shares) {
                const email = share.user?.email || "";
                const row = document.createElement("div");
                row.className = "share-row";
                row.innerHTML = `
                    <span class="share-email"></span>
                    <select>
                        <option value="viewer">Viewer</option>
                        <option value="editor">Editor</option>
                    </select>
                    <button type="button" class="danger">Revoke</button>
                `;
                row.querySelector(".share-email").textContent = email;
                const select = row.querySelector("select");
                const revokeButton = row.querySelector("button");
                select.value = share.role;
                select.setAttribute("aria-label", `Role for ${email}`);
                revokeButton.setAttribute("aria-label", `Revoke access for ${email}`);

                select.addEventListener("change", async () => {
                    setBusy(true);
                    try {
                        await api(`${sharesUrl}/${share.id}`, {
                            method: "PATCH",
                            body: JSON.stringify({role: select.value, version: share.version}),
                        });
                        await loadShares();
                        setFeedback(`${email} is now ${ROLE_LABELS[select.value].toLowerCase()}.`);
                    } catch (error) {
                        if (closeIfAccessLost(error)) {
                            return;
                        }
                        await loadShares();
                        setFeedback(error.message, true);
                    }
                });

                revokeButton.addEventListener("click", async () => {
                    const confirmed = await openConfirmModal({
                        title: "Revoke access",
                        message: `Revoke access for ${email}? They will lose access to this note immediately.`,
                        confirmLabel: "Revoke",
                    });
                    if (!confirmed || closed) {
                        return;
                    }

                    setBusy(true);
                    try {
                        await api(`${sharesUrl}/${share.id}`, {method: "DELETE"});
                        await loadShares();
                        setFeedback(`Access revoked for ${email}.`);
                    } catch (error) {
                        if (closeIfAccessLost(error)) {
                            return;
                        }
                        await loadShares();
                        setFeedback(error.message, true);
                    }
                });

                listEl.appendChild(row);
            }
        };

        async function loadShares() {
            listEl.innerHTML = `<p class="share-empty">Loading…</p>`;
            setBusy(true);
            try {
                const payload = await api(`${sharesUrl}?itemsPerPage=100`);
                if (closed) {
                    return;
                }
                renderShares(Array.isArray(payload?.items) ? payload.items : []);
            } catch (error) {
                if (closed || closeIfAccessLost(error)) {
                    return;
                }
                listEl.innerHTML = `
                    <p class="share-empty share-feedback--error">Could not load who has access.</p>
                    <button type="button" class="secondary" data-action="retry">Retry</button>
                `;
                listEl.querySelector('[data-action="retry"]').addEventListener("click", loadShares);
            } finally {
                if (!closed) {
                    setBusy(false);
                }
            }
        }

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const email = emailInput.value.trim();
            if (!email) {
                setFeedback("Enter the email of the person to share with.", true);
                emailInput.focus();
                return;
            }
            if (!emailInput.checkValidity()) {
                setFeedback("Enter a valid email address.", true);
                emailInput.focus();
                return;
            }

            setFeedback("");
            setBusy(true);
            try {
                await api(sharesUrl, {
                    method: "POST",
                    body: JSON.stringify({email, role: roleSelect.value}),
                });
                form.reset();
                await loadShares();
                setFeedback(`Shared with ${email}.`);
            } catch (error) {
                if (closeIfAccessLost(error)) {
                    return;
                }
                setBusy(false);
                setFeedback(
                    error.status === 404 ? `No registered user with the email ${email}.` : error.message,
                    true,
                );
            }
            if (!closed) {
                emailInput.focus();
            }
        });

        body.querySelector('[data-action="close"]').addEventListener("click", close);
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close();
            }
        });

        content.appendChild(body);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, close);
        emailInput.focus();
        loadShares();
    });
}

function openUpdateNoteModal(note) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Update Note");

        const form = document.createElement("form");
        form.className = "modal-form";
        form.innerHTML = `
            <label>Title <input type="text" name="title" required></label>
            <label>Content <textarea name="content" rows="8" required></textarea></label>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="cancel">Cancel</button>
                <button type="submit">Save</button>
            </div>
        `;

        const titleInput = form.querySelector('input[name="title"]');
        const contentInput = form.querySelector('textarea[name="content"]');
        titleInput.value = note.title || "";
        contentInput.value = note.content || "";

        const close = (result) => {
            backdrop.remove();
            resolve(result);
        };

        form.addEventListener("submit", (event) => {
            event.preventDefault();
            close({
                title: titleInput.value.trim(),
                content: contentInput.value,
            });
        });

        form.querySelector('[data-action="cancel"]').addEventListener("click", () => close(null));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(null);
            }
        });

        content.appendChild(form);
        document.body.appendChild(backdrop);
        titleInput.focus();
    });
}

function openDeleteNoteModal(noteTitle) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Delete Note");

        const body = document.createElement("div");
        body.className = "modal-form";
        body.innerHTML = `
            <p class="modal-message">Delete note "${escapeHtml(noteTitle || "")}"?</p>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="cancel">Cancel</button>
                <button type="button" class="danger" data-action="delete">Delete</button>
            </div>
        `;

        const close = (result) => {
            backdrop.remove();
            resolve(result);
        };

        body.querySelector('[data-action="cancel"]').addEventListener("click", () => close(false));
        body.querySelector('[data-action="delete"]').addEventListener("click", () => close(true));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(false);
            }
        });

        content.appendChild(body);
        document.body.appendChild(backdrop);
    });
}

const SORT_OPTIONS = {
    updated_desc: ["updatedAt", "desc"],
    updated_asc: ["updatedAt", "asc"],
    created_desc: ["createdAt", "desc"],
    created_asc: ["createdAt", "asc"],
    title_asc: ["title", "asc"],
    title_desc: ["title", "desc"],
};

// The filter controls and the keys they have in the page URL, with the value that means "not set".
const FILTER_CONTROLS = [
    ["q", searchQueryEl, ""],
    ["field", searchFieldEl, "all"],
    ["sort", searchSortEl, "updated_desc"],
    ["size", pageSizeEl, "10"],
    ["tag", tagFilterEl, ""],
    ["from", updatedFromEl, ""],
    ["to", updatedToEl, ""],
];

function isDateRangeInverted() {
    return Boolean(updatedFromEl.value && updatedToEl.value && updatedFromEl.value > updatedToEl.value);
}

function buildNotesSearchParams() {
    const params = new URLSearchParams();
    const query = searchQueryEl.value.trim();
    const field = searchFieldEl.value;
    const [sortBy, order] = SORT_OPTIONS[searchSortEl.value] || SORT_OPTIONS.updated_desc;

    if (query) {
        if (field === "title") {
            params.set("title", query);
        } else if (field === "content") {
            params.set("content", query);
        } else {
            params.set("q", query);
        }
    }

    params.set("sortBy", sortBy);
    params.set("order", order);

    if (isSharedView()) {
        params.set("scope", "shared");
    } else if (tagFilterEl.value) {
        params.set("tagIds", tagFilterEl.value);
    }

    if (updatedFromEl.value) {
        params.set("updatedFrom", updatedFromEl.value);
    }
    if (updatedToEl.value) {
        params.set("updatedTo", updatedToEl.value);
    }

    params.set("page", String(currentPage));
    params.set("limit", pageSizeEl.value);

    return params;
}

// Keeps the filters in the page URL, so a reload or a shared link shows the same list.
function writeFiltersToUrl() {
    if (!isNotesListVisible()) {
        return;
    }

    const url = new URL(window.location.href);
    for (const [key, control, unset] of FILTER_CONTROLS) {
        const value = key === "q" ? control.value.trim() : control.value;
        if (value && value !== unset && !(key === "tag" && isSharedView())) {
            url.searchParams.set(key, value);
        } else {
            url.searchParams.delete(key);
        }
    }
    if (currentPage > 1) {
        url.searchParams.set("page", String(currentPage));
    } else {
        url.searchParams.delete("page");
    }

    const query = url.searchParams.toString();
    window.history.replaceState({}, "", `${url.pathname}${query ? `?${query}` : ""}`);
}

function readFiltersFromUrl() {
    const params = new URLSearchParams(window.location.search);
    for (const [key, control, unset] of FILTER_CONTROLS) {
        const value = (params.get(key) ?? unset).slice(0, 200);
        control.value = value;
        // A select ignores values it has no option for; a date input ignores non-dates.
        if (control.value !== value) {
            control.value = unset;
        }
    }

    const page = Number.parseInt(params.get("page") || "1", 10);
    currentPage = Number.isFinite(page) && page >= 1 ? page : 1;
}

function hasActiveFilters() {
    return FILTER_CONTROLS.some(([key, control, unset]) => key !== "size"
        && !(key === "tag" && isSharedView())
        && !(key === "field" && !searchQueryEl.value.trim())
        && control.value.trim() !== unset);
}

function resetFilters() {
    for (const [key, control, unset] of FILTER_CONTROLS) {
        if (key !== "size") {
            control.value = unset;
        }
    }
    currentPage = 1;
    return refreshNotes().catch((error) => setStatus(error.message, true));
}

function renderActiveFilters() {
    const chips = [];
    const query = searchQueryEl.value.trim();
    if (query) {
        const where = {title: "Title", content: "Content"}[searchFieldEl.value] || "Text";
        chips.push([`${where}: ${query}`, () => {
            searchQueryEl.value = "";
            searchFieldEl.value = "all";
        }]);
    }
    if (tagFilterEl.value && !isSharedView()) {
        chips.push([`Tag: ${tagFilterEl.selectedOptions[0]?.textContent || ""}`, () => {
            tagFilterEl.value = "";
        }]);
    }
    if (updatedFromEl.value) {
        chips.push([`Updated from ${updatedFromEl.value}`, () => {
            updatedFromEl.value = "";
        }]);
    }
    if (updatedToEl.value) {
        chips.push([`Updated to ${updatedToEl.value}`, () => {
            updatedToEl.value = "";
        }]);
    }
    if (searchSortEl.value !== "updated_desc") {
        chips.push([`Sort: ${searchSortEl.selectedOptions[0]?.textContent || ""}`, () => {
            searchSortEl.value = "updated_desc";
        }]);
    }

    activeFilterChipsEl.innerHTML = "";
    for (const [label, clear] of chips) {
        const chip = document.createElement("li");
        chip.className = "chip";
        chip.innerHTML = `<span></span><button type="button" class="chip-remove">×</button>`;
        chip.querySelector("span").textContent = label;
        const removeButton = chip.querySelector("button");
        removeButton.setAttribute("aria-label", `Remove filter ${label}`);
        removeButton.addEventListener("click", () => {
            clear();
            currentPage = 1;
            refreshNotes().catch((error) => setStatus(error.message, true));
        });
        activeFilterChipsEl.appendChild(chip);
    }
    activeFiltersEl.classList.toggle("hidden", chips.length === 0);
}

function renderNotes() {
    listTotalEl.textContent = `${totalItems} note${totalItems === 1 ? "" : "s"}`;
    pageInfoEl.textContent = Number.isFinite(totalPages) ? `Page ${currentPage} / ${totalPages}` : `Page ${currentPage}`;
    prevPageEl.disabled = !hasPrevPage;
    nextPageEl.disabled = !hasNextPage;

    notesListEl.innerHTML = "";

    if (totalItems === 0) {
        if (hasActiveFilters()) {
            notesListEl.innerHTML = `
                <div class="panel notes-error">
                    <p>No notes match these filters.</p>
                    <button type="button" class="secondary" data-action="reset">Reset filters</button>
                </div>
            `;
            notesListEl.querySelector('[data-action="reset"]').addEventListener("click", resetFilters);
            return;
        }

        notesListEl.innerHTML = `<div class="panel">${
            isSharedView() ? "Nothing has been shared with you yet." : "No notes yet. Create your first note."
        }</div>`;
        return;
    }

    for (const note of notesCache) {
        // The role only decides what is offered here; the server enforces it on every request.
        const role = ROLE_LABELS[note.role] ? note.role : "owner";
        const canEdit = role === "owner" || role === "editor";
        const sharedBy = role === "owner"
            ? ""
            : `<p class="note-access"><span class="badge">${ROLE_LABELS[role]}${canEdit ? "" : " · read-only"}</span> Shared by ${escapeHtml(note.owner_email || "another user")}</p>`;

        const wrapper = document.createElement("article");
        wrapper.className = "panel note-item";
        wrapper.dataset.role = role;
        wrapper.innerHTML = `
            <label class="note-select"><input type="checkbox" data-action="select"> <span>Select</span></label>
            <h3>${escapeHtml(note.title || "")}</h3>
            ${sharedBy}
            ${taxonomyChipsHtml(note)}
            <p>${escapeHtml(note.content || "")}</p>
            <small>Updated: <time datetime="${escapeHtml(note.updated_at || "")}">${escapeHtml(formatTimestamp(note.updated_at))}</time></small>
            <div class="note-actions">
                ${canEdit ? `<button data-action="edit">Update</button>` : ""}
                ${canEdit ? `<button class="secondary" data-action="tags">Tags</button>` : ""}
                <button class="secondary" data-action="files">Files</button>
                <button class="secondary" data-action="history">History</button>
                <button class="secondary" data-action="activity">Activity</button>
                ${role === "owner" ? `<button class="secondary" data-action="share">Share</button>` : ""}
                ${role === "owner" ? `<button class="danger" data-action="delete">Delete</button>` : ""}
            </div>
        `;

        // Access can be revoked or downgraded while the page is open: say so and show the current state.
        const handleNoteError = async (error) => {
            if (role !== "owner" && isAccessLost(error)) {
                setStatus(
                    error.status === 403
                        ? "Your access to this note is now read-only."
                        : "This note is no longer shared with you.",
                    true,
                );
                await refreshNotes().catch(() => {});
                return;
            }
            setStatus(error.message, true);
        };

        wrapper.querySelector('[data-action="edit"]')?.addEventListener("click", async () => {
            const updates = await openUpdateNoteModal(note);
            if (!updates) {
                return;
            }

            try {
                if (await saveNote(note, updates)) {
                    setStatus("Note updated.");
                }
                await refreshNotes();
            } catch (error) {
                await handleNoteError(error);
            }
        });

        wrapper.querySelector('[data-action="activity"]').addEventListener("click", async () => {
            const result = await openActivityDialog(note);
            if (result?.error) {
                await handleNoteError(result.error);
            }
        });

        wrapper.querySelector('[data-action="history"]').addEventListener("click", async () => {
            const result = await openHistoryDialog(note, canEdit);
            if (result?.error) {
                await handleNoteError(result.error);
            } else if (result?.restored) {
                setStatus(`Revision ${result.restored} restored.`);
                await refreshNotes().catch((error) => setStatus(error.message, true));
            }
        });

        wrapper.querySelector('[data-action="tags"]')?.addEventListener("click", async () => {
            const result = await openNoteTaxonomyDialog(note);
            if (result?.error) {
                await handleNoteError(result.error);
            } else if (result?.saved) {
                setStatus("Tags and category updated.");
                await refreshNotes().catch((error) => setStatus(error.message, true));
            }
        });

        wrapper.querySelector('[data-action="files"]').addEventListener("click", async () => {
            const result = await openAttachmentsDialog(note, canEdit);
            if (result?.error) {
                await handleNoteError(result.error);
            }
        });

        wrapper.querySelector('[data-action="share"]')?.addEventListener("click", () => {
            openShareDialog(note);
        });

        wrapper.querySelector('[data-action="delete"]')?.addEventListener("click", async () => {
            const confirmed = await openDeleteNoteModal(note.title || "");
            if (!confirmed) {
                return;
            }

            try {
                await api(`/api/notes/${note.id}`, {method: "DELETE"});
                setStatus("Note deleted.");
                await refreshNotes();
            } catch (error) {
                await handleNoteError(error);
            }
        });

        const selectBox = wrapper.querySelector('[data-action="select"]');
        selectBox.checked = selectedNotes.has(note.id);
        selectBox.setAttribute("aria-label", `Select note ${note.title || ""}`);
        selectBox.addEventListener("change", () => {
            if (selectBox.checked) {
                selectedNotes.set(note.id, note.title || "");
            } else {
                selectedNotes.delete(note.id);
            }
            renderBulkBar();
        });

        notesListEl.appendChild(wrapper);
    }

    renderBulkBar();
}

function renderBulkBar() {
    const onPage = notesCache.filter((note) => selectedNotes.has(note.id)).length;
    bulkSelectPageEl.disabled = notesCache.length === 0;
    bulkSelectPageEl.checked = notesCache.length > 0 && onPage === notesCache.length;
    bulkSelectPageEl.indeterminate = onPage > 0 && onPage < notesCache.length;

    const elsewhere = selectedNotes.size - onPage;
    bulkCountEl.textContent = `${selectedNotes.size} selected${elsewhere > 0 ? ` (${elsewhere} not on this page)` : ""}`;
    bulkActionsEl.classList.toggle("hidden", selectedNotes.size === 0);
}

function clearSelection() {
    selectedNotes.clear();
    for (const box of notesListEl.querySelectorAll('[data-action="select"]')) {
        box.checked = false;
    }
    renderBulkBar();
}

const BULK_VERBS = {setCategory: "updated", addTags: "tagged", removeTags: "untagged", delete: "deleted"};

// Sends one bulk operation (in requests of at most 100 notes) and reports what could not be done.
async function runBulk(operation, payload, notes) {
    const ids = Array.from(notes.keys());
    const succeeded = [];
    const failed = [];
    for (const button of bulkActionsEl.querySelectorAll("button")) {
        button.disabled = true;
    }
    bulkReportEl.classList.add("hidden");

    try {
        for (let start = 0; start < ids.length; start += BULK_MAX_IDS) {
            const result = await api("/api/notes/bulk", {
                method: "POST",
                body: JSON.stringify({operation, ids: ids.slice(start, start + BULK_MAX_IDS), payload}),
            });
            succeeded.push(...(Array.isArray(result.succeeded) ? result.succeeded : []));
            failed.push(...(Array.isArray(result.failed) ? result.failed : []));
        }
    } catch (error) {
        // Nothing is known about the notes of the request that did not come back: keep them selected.
        const done = new Set([...succeeded, ...failed.map((item) => item.id)]);
        for (const id of ids) {
            if (!done.has(id)) {
                failed.push({id, code: "request_failed", message: retryHint(error)});
            }
        }
    } finally {
        for (const button of bulkActionsEl.querySelectorAll("button")) {
            button.disabled = false;
        }
    }

    // What failed stays selected, so it can be retried or handled differently.
    selectedNotes.clear();
    for (const item of failed) {
        selectedNotes.set(item.id, notes.get(item.id) ?? "");
    }

    const summary = `${succeeded.length} of ${ids.length} note${ids.length === 1 ? "" : "s"} ${BULK_VERBS[operation]}.`;
    setStatus(failed.length === 0 ? summary : `${summary} ${failed.length} failed.`, failed.length > 0);
    renderBulkReport(operation, payload, notes, failed, summary);
    await refreshNotes().catch(() => {});
}

function renderBulkReport(operation, payload, notes, failed, summary) {
    bulkReportEl.innerHTML = "";
    bulkReportEl.classList.toggle("hidden", failed.length === 0);
    if (failed.length === 0) {
        return;
    }

    const heading = document.createElement("p");
    heading.textContent = `${summary} These ${failed.length} could not be ${BULK_VERBS[operation]} and are still selected:`;
    const list = document.createElement("ul");
    list.className = "bulk-failures";
    for (const item of failed.slice(0, 20)) {
        const entry = document.createElement("li");
        entry.textContent = `${notes.get(item.id) || item.id}: ${item.message}`;
        list.appendChild(entry);
    }
    if (failed.length > 20) {
        const more = document.createElement("li");
        more.textContent = `…and ${failed.length - 20} more.`;
        list.appendChild(more);
    }

    const actions = document.createElement("div");
    actions.className = "modal-actions";
    actions.innerHTML = `
        <button type="button" class="secondary" data-action="dismiss">Dismiss</button>
        <button type="button" data-action="retry">Retry failed</button>
    `;
    actions.querySelector('[data-action="dismiss"]').addEventListener("click", () => {
        bulkReportEl.classList.add("hidden");
    });
    actions.querySelector('[data-action="retry"]').addEventListener("click", () => {
        runBulk(operation, payload, new Map(failed.map((item) => [item.id, notes.get(item.id) ?? ""])));
    });

    bulkReportEl.append(heading, list, actions);
}

function setProfile(profile) {
    accountProfile = profile;
    profileEmailEl.textContent = profile?.email ?? "-";
    profileIdEl.textContent = profile?.id ?? "-";
    profilePendingEl.classList.toggle("hidden", !profile?.pending_email);
    document.getElementById("profile-pending-email").textContent = profile?.pending_email ?? "";
}

// Timestamps are shown in the time zone chosen in the account settings.
function formatTimestamp(value) {
    const date = value ? new Date(value) : null;
    if (!date || Number.isNaN(date.getTime())) {
        return "-";
    }

    try {
        const text = new Intl.DateTimeFormat("en-CA", {
            timeZone: userTimezone,
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            hourCycle: "h23",
        }).format(date).replace(",", "");
        return `${text} (${userTimezone})`;
    } catch {
        return value;
    }
}

function setFeedback(element, message, isError = false) {
    element.textContent = message;
    element.classList.toggle("share-feedback--error", Boolean(message) && isError);
    element.classList.toggle("share-feedback--ok", Boolean(message) && !isError);
}

function retryHint(error) {
    return error?.status === 429 ? "Too many attempts. Wait a few minutes and try again." : error.message;
}

function showAuthCard(card) {
    forgotCardEl.classList.toggle("hidden", card !== "forgot");
    resetCardEl.classList.toggle("hidden", card !== "reset");
}

function fillTimezones(selected) {
    let zones = [];
    try {
        zones = Intl.supportedValuesOf("timeZone");
    } catch {
        zones = [];
    }
    const names = Array.from(new Set(["UTC", selected, ...zones])).filter(Boolean);
    settingsTimezoneEl.innerHTML = "";
    for (const name of names) {
        const option = document.createElement("option");
        option.value = name;
        option.textContent = name;
        settingsTimezoneEl.appendChild(option);
    }
    settingsTimezoneEl.value = selected;
}

async function loadSettings() {
    try {
        const settings = await api("/api/account/settings");
        userTimezone = typeof settings?.timezone === "string" ? settings.timezone : "UTC";
    } catch {
        userTimezone = "UTC";
    }
    fillTimezones(userTimezone);
}

async function loadAccountPage() {
    loadNotificationPreferences();
    setFeedback(changeEmailFeedbackEl, "");
    setFeedback(settingsFeedbackEl, "");
    try {
        setProfile(await api("/api/account/profile"));
    } catch (error) {
        setStatus(error.message, true);
    }
    await loadSettings();
}

// The session is over (password or email changed elsewhere, or the token expired).
function signOutLocally(message, isError = false) {
    navAuditButtonEl.classList.add("hidden");
    setUnreadNotifications(0);
    selectedNotes.clear();
    clearToken();
    notesCache = [];
    notesListEl.innerHTML = "";
    setProfile(null);
    userTimezone = "UTC";
    navigateTo("/");
    showAuthUI();
    setStatus(message, isError);
}

function openChangePasswordDialog() {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Change password");

        const form = document.createElement("form");
        form.className = "modal-form";
        form.noValidate = true;
        form.innerHTML = `
            <label>Current password <input type="password" name="current" autocomplete="current-password" required></label>
            <label>New password
                <input type="password" name="new" autocomplete="new-password" minlength="12" maxlength="128" required aria-describedby="change-password-hint">
            </label>
            <label>Repeat new password <input type="password" name="repeat" autocomplete="new-password" required></label>
            <small id="change-password-hint">12 to 128 characters. You will be signed out on every other device.</small>
            <p class="share-feedback share-feedback--error" role="alert"></p>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="cancel">Cancel</button>
                <button type="submit">Change password</button>
            </div>
        `;
        const current = form.querySelector('input[name="current"]');
        const next = form.querySelector('input[name="new"]');
        const repeat = form.querySelector('input[name="repeat"]');
        const feedback = form.querySelector(".share-feedback");
        const submit = form.querySelector('button[type="submit"]');

        let deactivate = () => {};
        const close = (result) => {
            backdrop.remove();
            deactivate();
            resolve(result);
        };

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const problem = passwordFormProblem(next.value, repeat.value) || (current.value ? "" : "Enter your current password.");
            if (problem) {
                feedback.textContent = problem;
                (current.value ? next : current).focus();
                return;
            }

            feedback.textContent = "";
            submit.disabled = true;
            try {
                const result = await api("/api/account/change-password", {
                    method: "POST",
                    body: JSON.stringify({current_password: current.value, new_password: next.value}),
                });
                // The old token stopped working with the change.
                setToken(result.token);
                close(true);
            } catch (error) {
                submit.disabled = false;
                feedback.textContent = retryHint(error);
                if (error.status === 403) {
                    current.focus();
                    current.select();
                }
            }
        });
        form.querySelector('[data-action="cancel"]').addEventListener("click", () => close(false));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(false);
            }
        });

        content.appendChild(form);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, () => close(false));
        current.focus();
    });
}

function passwordFormProblem(password, repeated) {
    const length = Array.from(password).length;
    if (length < 12) {
        return "The new password must be at least 12 characters.";
    }
    if (length > 128) {
        return "The new password cannot be longer than 128 characters.";
    }
    if (password !== repeated) {
        return "The two new passwords do not match.";
    }
    return "";
}

function colorDotHtml(color) {
    return /^#[0-9a-f]{6}$/i.test(color || "") ? `<span class="color-dot" style="background:${color}"></span>` : "";
}

function taxonomyChipsHtml(note) {
    const tags = Array.isArray(note.tags) ? note.tags : [];
    if (!note.category && tags.length === 0) {
        return "";
    }

    const category = note.category
        ? `<span class="chip chip--category" title="Category">${colorDotHtml(note.category.color)}${escapeHtml(note.category.name || "")}</span>`
        : "";
    const chips = tags
        .map((tag) => `<li class="chip">${colorDotHtml(tag.color)}${escapeHtml(tag.name || "")}</li>`)
        .join("");

    return `<div class="note-taxonomy">${category}<ul class="chip-list" aria-label="Tags">${chips}</ul></div>`;
}

// Tags and category of one note: a category dropdown and tag chips with autocomplete.
// The choices are the note owner's taxonomy, which is what the server accepts.
function openNoteTaxonomyDialog(note) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Tags and category");
        const listId = `tag-options-${modalCounter}`;

        const body = document.createElement("form");
        body.className = "modal-form";
        body.noValidate = true;
        body.innerHTML = `
            <p class="modal-message share-note-title"></p>
            <div class="taxonomy-dialog-body">
                <label>Category
                    <select name="category"><option value="">No category</option></select>
                </label>
                <div>
                    <label>Add a tag
                        <input type="text" name="tag" autocomplete="off" role="combobox" aria-autocomplete="list"
                               aria-expanded="false" aria-controls="${listId}" placeholder="Type to search tags">
                    </label>
                    <ul id="${listId}" class="tag-options hidden" role="listbox" aria-label="Matching tags"></ul>
                </div>
                <ul class="chip-list chip-list--editable" aria-label="Tags on this note"></ul>
                <p class="share-empty tag-count" aria-live="polite"></p>
            </div>
            <p class="share-feedback" role="alert"></p>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="cancel">Cancel</button>
                <button type="submit">Save</button>
            </div>
        `;
        body.querySelector(".share-note-title").textContent = note.title || "";

        const dialogBody = body.querySelector(".taxonomy-dialog-body");
        const categorySelect = body.querySelector('select[name="category"]');
        const tagInput = body.querySelector('input[name="tag"]');
        const optionsEl = body.querySelector(".tag-options");
        const chipsEl = body.querySelector(".chip-list--editable");
        const countEl = body.querySelector(".tag-count");
        const feedbackEl = body.querySelector(".share-feedback");
        const saveButton = body.querySelector('button[type="submit"]');

        let available = [];
        let selected = (Array.isArray(note.tags) ? note.tags : []).map((tag) => ({...tag}));
        let activeOption = -1;
        let closed = false;
        let deactivate = () => {};

        const close = (result) => {
            if (closed) {
                return;
            }
            closed = true;
            backdrop.remove();
            deactivate();
            resolve(result);
        };

        const setFeedback = (message) => {
            feedbackEl.textContent = message;
            feedbackEl.classList.toggle("share-feedback--error", Boolean(message));
        };

        const matches = () => {
            const query = tagInput.value.trim().toLowerCase();
            return available
                .filter((tag) => !selected.some((chosen) => chosen.id === tag.id))
                .filter((tag) => !query || tag.name.toLowerCase().includes(query))
                .slice(0, 8);
        };

        const hideOptions = () => {
            optionsEl.classList.add("hidden");
            tagInput.setAttribute("aria-expanded", "false");
            tagInput.removeAttribute("aria-activedescendant");
            activeOption = -1;
        };

        const renderOptions = () => {
            const found = matches();
            optionsEl.innerHTML = "";
            if (found.length === 0) {
                const empty = document.createElement("li");
                empty.className = "tag-option tag-option--empty";
                empty.textContent = available.length === 0 ? "No tags yet. Create them on the Tags page." : "No matching tags.";
                optionsEl.appendChild(empty);
            }
            found.forEach((tag, index) => {
                const option = document.createElement("li");
                option.id = `${listId}-${index}`;
                option.className = "tag-option";
                option.setAttribute("role", "option");
                option.setAttribute("aria-selected", index === activeOption ? "true" : "false");
                option.innerHTML = `${colorDotHtml(tag.color)}${escapeHtml(tag.name)}`;
                // mousedown, so the choice is made before the input loses focus.
                option.addEventListener("mousedown", (event) => {
                    event.preventDefault();
                    addTag(tag);
                });
                optionsEl.appendChild(option);
            });
            optionsEl.classList.remove("hidden");
            tagInput.setAttribute("aria-expanded", "true");
            if (activeOption >= 0 && found[activeOption]) {
                tagInput.setAttribute("aria-activedescendant", `${listId}-${activeOption}`);
            } else {
                tagInput.removeAttribute("aria-activedescendant");
            }
        };

        const renderChips = () => {
            chipsEl.innerHTML = "";
            for (const tag of selected) {
                const chip = document.createElement("li");
                chip.className = "chip";
                chip.innerHTML = `${colorDotHtml(tag.color)}<span></span><button type="button" class="chip-remove">×</button>`;
                chip.querySelector("span:last-of-type").textContent = tag.name;
                const removeButton = chip.querySelector("button");
                removeButton.setAttribute("aria-label", `Remove tag ${tag.name}`);
                removeButton.addEventListener("click", () => {
                    selected = selected.filter((chosen) => chosen.id !== tag.id);
                    renderChips();
                    tagInput.focus();
                });
                chipsEl.appendChild(chip);
            }
            countEl.textContent = `${selected.length} of ${MAX_TAGS_PER_NOTE} tags`;
            tagInput.disabled = selected.length >= MAX_TAGS_PER_NOTE;
            if (tagInput.disabled) {
                hideOptions();
            }
        };

        function addTag(tag) {
            if (selected.length >= MAX_TAGS_PER_NOTE) {
                setFeedback(`A note can have at most ${MAX_TAGS_PER_NOTE} tags.`);
                return;
            }
            setFeedback("");
            selected.push(tag);
            tagInput.value = "";
            activeOption = -1;
            renderChips();
            if (!tagInput.disabled) {
                renderOptions();
            }
        }

        tagInput.addEventListener("input", () => {
            setFeedback("");
            activeOption = -1;
            renderOptions();
        });
        // The list sits above the buttons. Closing it only after a click has landed
        // keeps the button under the pointer from moving away mid-click.
        let hideTimer = 0;
        tagInput.addEventListener("focus", () => {
            clearTimeout(hideTimer);
            renderOptions();
        });
        tagInput.addEventListener("blur", () => {
            hideTimer = setTimeout(hideOptions, 200);
        });
        tagInput.addEventListener("keydown", (event) => {
            const found = matches();
            if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                event.preventDefault();
                if (found.length > 0) {
                    const step = event.key === "ArrowDown" ? 1 : -1;
                    activeOption = (activeOption + step + found.length) % found.length;
                    renderOptions();
                }
            } else if (event.key === "Enter") {
                // Enter picks a tag; it must not submit the form while the list is in use.
                if (!optionsEl.classList.contains("hidden") && (activeOption >= 0 || tagInput.value.trim())) {
                    event.preventDefault();
                    const choice = found[Math.max(activeOption, 0)];
                    if (choice) {
                        addTag(choice);
                    } else {
                        setFeedback("No tag with this name. Create it on the Tags page first.");
                    }
                }
            } else if (event.key === "Escape" && !optionsEl.classList.contains("hidden")) {
                event.preventDefault();
                event.stopPropagation();
                hideOptions();
            } else if (event.key === "Backspace" && tagInput.value === "" && selected.length > 0) {
                selected = selected.slice(0, -1);
                renderChips();
            }
        });

        body.addEventListener("submit", async (event) => {
            event.preventDefault();
            setFeedback("");
            saveButton.disabled = true;
            try {
                await api(`/api/notes/${note.id}/tags`, {
                    method: "PUT",
                    body: JSON.stringify({tag_ids: selected.map((tag) => tag.id)}),
                });
                await api(`/api/notes/${note.id}/category`, {
                    method: "PUT",
                    body: JSON.stringify({category_id: categorySelect.value || null}),
                });
                close({saved: true});
            } catch (error) {
                if (error.status === 403 || (error.status === 404 && error.message === "Note not found.")) {
                    close({error});
                    return;
                }
                saveButton.disabled = false;
                // A tag or category was deleted meanwhile: show what is still available.
                setFeedback(error.status === 404 ? `${error.message} It may have been deleted; the lists were reloaded.` : error.message);
                if (error.status === 404) {
                    await load();
                }
            }
        });

        async function load() {
            dialogBody.setAttribute("aria-busy", "true");
            saveButton.disabled = true;
            try {
                const taxonomy = await api(`/api/notes/${note.id}/taxonomy`);
                if (closed) {
                    return;
                }
                available = Array.isArray(taxonomy.tags) ? taxonomy.tags : [];
                selected = selected.filter((chosen) => available.some((tag) => tag.id === chosen.id));

                const wanted = categorySelect.dataset.loaded ? categorySelect.value : (note.category?.id || "");
                categorySelect.innerHTML = `<option value="">No category</option>`;
                for (const category of Array.isArray(taxonomy.categories) ? taxonomy.categories : []) {
                    const option = document.createElement("option");
                    option.value = category.id;
                    option.textContent = category.name;
                    categorySelect.appendChild(option);
                }
                categorySelect.value = wanted;
                categorySelect.dataset.loaded = "1";
                renderChips();
                saveButton.disabled = false;
            } catch (error) {
                if (closed) {
                    return;
                }
                if (error.status === 403 || error.status === 404) {
                    close({error});
                    return;
                }
                setFeedback("Could not load tags and categories.");
                const retry = document.createElement("button");
                retry.type = "button";
                retry.className = "secondary";
                retry.textContent = "Retry";
                retry.addEventListener("click", () => {
                    setFeedback("");
                    load();
                });
                feedbackEl.append(" ", retry);
            } finally {
                dialogBody.setAttribute("aria-busy", "false");
            }
        }

        body.querySelector('[data-action="cancel"]').addEventListener("click", () => close(null));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(null);
            }
        });

        content.appendChild(body);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, () => close(null));
        renderChips();
        categorySelect.focus();
        load();
    });
}

// Saves an edit based on the version the user opened. If the note changed meanwhile the server
// refuses (412) and the user decides what happens to their text; nothing is overwritten silently.
async function saveNote(note, updates) {
    let basedOn = note.version;
    for (;;) {
        try {
            await api(`/api/notes/${note.id}`, {
                method: "PATCH",
                headers: {"If-Match": `"${basedOn}"`},
                body: JSON.stringify({title: updates.title, content: updates.content}),
            });
            return true;
        } catch (error) {
            if (error.status !== 412) {
                throw error;
            }
        }

        const latest = await api(`/api/notes/${note.id}`);
        const choice = await openConflictModal(latest, updates);
        if (choice !== "overwrite") {
            setStatus(choice === "discard" ? "Your changes were discarded; the note shows the other version." : "Not saved. Your text was not applied.", choice !== "discard");
            return false;
        }
        basedOn = latest.version;
    }
}

function openConflictModal(latest, mine) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Note changed by someone else");

        const body = document.createElement("div");
        body.className = "modal-form";
        body.innerHTML = `
            <p class="modal-message">This note was saved by someone else while you were editing. Your text has not been saved.</p>
            <div class="diff-columns">
                <section aria-label="Their version">
                    <h4 class="share-heading">Their version (saved)</h4>
                    <p class="diff-title" data-role="their-title"></p>
                    <pre class="diff-pane" data-role="their-content" tabindex="0"></pre>
                </section>
                <section aria-label="Your version">
                    <h4 class="share-heading">Your version (not saved)</h4>
                    <p class="diff-title" data-role="my-title"></p>
                    <pre class="diff-pane" data-role="my-content" tabindex="0"></pre>
                </section>
            </div>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="cancel">Cancel</button>
                <button type="button" class="secondary" data-action="discard">Keep their version</button>
                <button type="button" class="danger" data-action="overwrite">Overwrite with mine</button>
            </div>
        `;
        body.querySelector('[data-role="their-title"]').textContent = latest.title || "";
        body.querySelector('[data-role="my-title"]').textContent = mine.title || "";
        renderLineDiff(
            body.querySelector('[data-role="their-content"]'),
            body.querySelector('[data-role="my-content"]'),
            latest.content || "",
            mine.content || "",
        );

        let deactivate = () => {};
        const close = (result) => {
            backdrop.remove();
            deactivate();
            resolve(result);
        };
        for (const action of ["cancel", "discard", "overwrite"]) {
            body.querySelector(`[data-action="${action}"]`).addEventListener("click", () => close(action));
        }

        content.appendChild(body);
        document.body.appendChild(backdrop);
        backdrop.querySelector(".modal-dialog").classList.add("modal-dialog--wide");
        deactivate = activateDialog(backdrop, () => close("cancel"));
        body.querySelector('[data-action="cancel"]').focus();
    });
}

// Which lines of two texts differ (longest common subsequence). Returns one flag per line of each side.
function diffLines(leftText, rightText) {
    const left = leftText.split("\n");
    const right = rightText.split("\n");
    const leftChanged = new Array(left.length).fill(true);
    const rightChanged = new Array(right.length).fill(true);

    // Very long texts are shown without highlighting rather than freezing the page.
    if (left.length * right.length <= 250000) {
        const table = Array.from({length: left.length + 1}, () => new Uint16Array(right.length + 1));
        for (let i = left.length - 1; i >= 0; i -= 1) {
            for (let j = right.length - 1; j >= 0; j -= 1) {
                table[i][j] = left[i] === right[j] ? table[i + 1][j + 1] + 1 : Math.max(table[i + 1][j], table[i][j + 1]);
            }
        }
        let i = 0;
        let j = 0;
        while (i < left.length && j < right.length) {
            if (left[i] === right[j]) {
                leftChanged[i] = false;
                rightChanged[j] = false;
                i += 1;
                j += 1;
            } else if (table[i + 1][j] >= table[i][j + 1]) {
                i += 1;
            } else {
                j += 1;
            }
        }
    } else {
        leftChanged.fill(false);
        rightChanged.fill(false);
    }

    return {left, right, leftChanged, rightChanged};
}

// Fills two panes with the texts, marking removed lines on the left and added lines on the right.
// Lines are set as text, never as HTML.
function renderLineDiff(leftPane, rightPane, leftText, rightText) {
    const diff = diffLines(leftText, rightText);
    const fill = (pane, lines, changed, className, label) => {
        pane.innerHTML = "";
        lines.forEach((line, index) => {
            const row = document.createElement("span");
            row.className = changed[index] ? `diff-line ${className}` : "diff-line";
            if (changed[index]) {
                row.setAttribute("aria-label", `${label}: ${line}`);
            }
            row.textContent = line === "" ? " " : line;
            pane.appendChild(row);
        });
    };
    fill(leftPane, diff.left, diff.leftChanged, "diff-line--removed", "Only here");
    fill(rightPane, diff.right, diff.rightChanged, "diff-line--added", "Only here");

    return diff.leftChanged.some(Boolean) || diff.rightChanged.some(Boolean);
}

function openHistoryDialog(note, canEdit) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("History");

        const body = document.createElement("div");
        body.className = "modal-form";
        body.innerHTML = `
            <p class="modal-message share-note-title"></p>
            <div class="history-layout">
                <div>
                    <h4 class="share-heading">Revisions</h4>
                    <ul class="history-list" data-role="list" aria-label="Revisions" aria-live="polite"></ul>
                </div>
                <div data-role="detail" class="history-detail">
                    <p class="share-empty">Choose a revision to compare it with the current text.</p>
                </div>
            </div>
            <p class="share-feedback" role="alert"></p>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="close">Close</button>
            </div>
        `;
        body.querySelector(".share-note-title").textContent = note.title || "";

        const listEl = body.querySelector('[data-role="list"]');
        const detailEl = body.querySelector('[data-role="detail"]');
        const feedbackEl = body.querySelector(".share-feedback");
        let currentVersion = note.version;
        let current = {title: note.title || "", content: note.content || ""};
        let closed = false;
        let deactivate = () => {};
        const close = (result) => {
            if (closed) {
                return;
            }
            closed = true;
            backdrop.remove();
            deactivate();
            resolve(result);
        };
        const lost = (error) => {
            if (error?.status === 403 || (error?.status === 404 && error.message === "Note not found.")) {
                close({error});
                return true;
            }
            return false;
        };

        const kindLabel = (revision) => ({create: "created", update: "edited", restore: `restored from revision ${revision.restored_from}`}[revision.kind] || revision.kind);

        async function showRevision(revision, button) {
            for (const other of listEl.querySelectorAll("button")) {
                other.setAttribute("aria-pressed", other === button ? "true" : "false");
            }
            detailEl.innerHTML = `<p class="share-empty">Loading…</p>`;
            setFeedback(feedbackEl, "");
            let full;
            try {
                full = await api(`/api/notes/${note.id}/revisions/${revision.revision}`);
            } catch (error) {
                if (!lost(error)) {
                    detailEl.innerHTML = `<p class="share-empty share-feedback--error"></p>`;
                    detailEl.firstElementChild.textContent = error.status === 404 ? "This revision is no longer kept." : "Could not load this revision.";
                }
                return;
            }
            if (closed) {
                return;
            }

            const isCurrent = revision.revision === currentVersion;
            detailEl.innerHTML = `
                <div class="diff-columns">
                    <section aria-label="Selected revision">
                        <h4 class="share-heading" data-role="left-heading"></h4>
                        <p class="diff-title" data-role="left-title"></p>
                        <pre class="diff-pane" data-role="left" tabindex="0"></pre>
                    </section>
                    <section aria-label="Current text">
                        <h4 class="share-heading">Current (revision ${Number(currentVersion)})</h4>
                        <p class="diff-title" data-role="right-title"></p>
                        <pre class="diff-pane" data-role="right" tabindex="0"></pre>
                    </section>
                </div>
                <p class="share-empty" data-role="summary"></p>
                ${canEdit && !isCurrent ? `<button type="button" data-action="restore">Restore revision ${Number(revision.revision)}</button>` : ""}
            `;
            detailEl.querySelector('[data-role="left-heading"]').textContent = `Revision ${revision.revision}`;
            detailEl.querySelector('[data-role="left-title"]').textContent = full.title || "";
            detailEl.querySelector('[data-role="right-title"]').textContent = current.title;
            const differs = renderLineDiff(
                detailEl.querySelector('[data-role="left"]'),
                detailEl.querySelector('[data-role="right"]'),
                full.content || "",
                current.content,
            );
            const sameTitle = (full.title || "") === current.title;
            detailEl.querySelector('[data-role="summary"]').textContent = isCurrent
                ? "This is the current text."
                : (differs || !sameTitle ? "Highlighted lines differ between the two." : "Same text as the current revision.");

            detailEl.querySelector('[data-action="restore"]')?.addEventListener("click", async () => {
                const confirmed = await openConfirmModal({
                    title: "Restore revision",
                    message: `Replace the current text with revision ${revision.revision}? The current text stays in the history.`,
                    confirmLabel: "Restore",
                });
                if (!confirmed || closed) {
                    return;
                }
                try {
                    await api(`/api/notes/${note.id}/revisions/${revision.revision}/restore`, {
                        method: "POST",
                        headers: {"If-Match": `"${currentVersion}"`},
                    });
                    close({restored: revision.revision});
                } catch (error) {
                    if (lost(error)) {
                        return;
                    }
                    // Somebody saved in between: show what is current now before anything is replaced.
                    setFeedback(feedbackEl, error.status === 412 ? "The note was changed in the meantime. The history was reloaded; check the current text and restore again if you still want to." : error.message, true);
                    await load();
                }
            });
        }

        async function load() {
            listEl.innerHTML = `<li class="share-empty">Loading…</li>`;
            detailEl.innerHTML = `<p class="share-empty">Choose a revision to compare it with the current text.</p>`;
            try {
                const [latest, history] = await Promise.all([
                    api(`/api/notes/${note.id}`),
                    api(`/api/notes/${note.id}/revisions?itemsPerPage=100`),
                ]);
                if (closed) {
                    return;
                }
                current = {title: latest.title || "", content: latest.content || ""};
                currentVersion = latest.version;
                listEl.innerHTML = "";
                const items = Array.isArray(history?.items) ? history.items : [];
                if (items.length === 0) {
                    listEl.innerHTML = `<li class="share-empty">No earlier revisions are kept for this note.</li>`;
                    return;
                }
                for (const revision of items) {
                    const item = document.createElement("li");
                    const button = document.createElement("button");
                    button.type = "button";
                    button.className = "history-item";
                    button.setAttribute("aria-pressed", "false");
                    const who = revision.actor?.email || "a deleted account";
                    button.innerHTML = `<strong></strong><span></span><span></span>`;
                    button.children[0].textContent = `Revision ${revision.revision}${revision.revision === currentVersion ? " (current)" : ""}`;
                    button.children[1].textContent = `${kindLabel(revision)} by ${who}`;
                    button.children[2].textContent = formatTimestamp(revision.created_at);
                    button.addEventListener("click", () => showRevision(revision, button));
                    item.appendChild(button);
                    listEl.appendChild(item);
                }
            } catch (error) {
                if (closed || lost(error)) {
                    return;
                }
                listEl.innerHTML = `<li><p class="share-empty share-feedback--error">Could not load the history.</p><button type="button" class="secondary" data-action="retry">Retry</button></li>`;
                listEl.querySelector('[data-action="retry"]').addEventListener("click", load);
            }
        }

        body.querySelector('[data-action="close"]').addEventListener("click", () => close(null));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(null);
            }
        });

        content.appendChild(body);
        document.body.appendChild(backdrop);
        backdrop.querySelector(".modal-dialog").classList.add("modal-dialog--wide");
        deactivate = activateDialog(backdrop, () => close(null));
        body.querySelector('[data-action="close"]').focus();
        load();
    });
}

// ----- Audit log -----

// Says what an audit event means, from its type and its summary. The summary never holds the changed text.
function describeAuditEvent(event) {
    const details = event.details || {};
    const target = event.target_user?.email || "a user";
    const changed = Array.isArray(details.changed) ? details.changed.join(" and ") : "";
    const texts = {
        "note.created": "created the note",
        "note.updated": `changed the ${changed || "note"}${details.version ? ` (revision ${details.version})` : ""}`,
        "note.deleted": "deleted the note",
        "note.restored": `restored revision ${details.from_revision ?? "?"}`,
        "note.tags_changed": `changed the tags (${(details.added || []).length} added, ${(details.removed || []).length} removed)`,
        "note.category_changed": details.to ? "set the category" : "removed the category",
        "share.granted": `shared the note with ${target} as ${details.role || "?"}`,
        "share.role_changed": `changed ${target} from ${details.from || "?"} to ${details.to || "?"}`,
        "share.revoked": `removed the access of ${target}`,
        "share.removed_with_note": `access of ${target} ended with the note`,
        "attachment.uploaded": `attached a file (${details.mime_type || "file"}, ${formatBytes(Number(details.size) || 0)})`,
        "attachment.deleted": "deleted an attached file",
    };
    return texts[event.type] || event.type;
}

function buildAuditItem(event, withIds) {
    const item = document.createElement("li");
    item.className = "timeline-item";
    item.innerHTML = `<p class="timeline-text"></p><small></small>`;
    const who = event.actor ? (event.actor.email || "a deleted account") : "The system";
    item.querySelector(".timeline-text").textContent = `${who} ${describeAuditEvent(event)}`;
    const parts = [formatTimestamp(event.created_at), event.type];
    if (withIds) {
        parts.push(event.note_id ? `note ${event.note_id}` : "no note", event.correlation_id ? `request ${event.correlation_id}` : "");
    }
    item.querySelector("small").textContent = parts.filter(Boolean).join(" · ");
    return item;
}

// Loads one page of a cursor-paged audit list into a list element and wires "Show older".
function createAuditPager({listEl, moreButton, feedbackEl, url, withIds, emptyText, onError}) {
    let cursor = null;
    async function load(reset) {
        if (reset) {
            cursor = null;
            listEl.innerHTML = `<li class="share-empty">Loading…</li>`;
            moreButton.classList.add("hidden");
        }
        moreButton.disabled = true;
        setFeedback(feedbackEl, "");
        try {
            const address = new URL(url(), window.location.origin);
            address.searchParams.set("limit", "20");
            if (cursor) {
                address.searchParams.set("cursor", cursor);
            }
            const page = await api(`${address.pathname}${address.search}`);
            if (reset) {
                listEl.innerHTML = "";
            }
            const items = Array.isArray(page?.items) ? page.items : [];
            for (const event of items) {
                listEl.appendChild(buildAuditItem(event, withIds));
            }
            if (listEl.childElementCount === 0) {
                listEl.innerHTML = `<li class="share-empty"></li>`;
                listEl.firstElementChild.textContent = emptyText;
            }
            cursor = page?.next_cursor || null;
            moreButton.classList.toggle("hidden", !cursor);
        } catch (error) {
            if (onError?.(error)) {
                return;
            }
            if (reset) {
                listEl.innerHTML = "";
            }
            feedbackEl.textContent = "";
            setFeedback(feedbackEl, `${error.status === 422 || error.status === 403 ? error.message : "Could not load the events."} `, true);
            const retry = document.createElement("button");
            retry.type = "button";
            retry.className = "secondary";
            retry.textContent = "Retry";
            retry.addEventListener("click", () => load(reset));
            feedbackEl.appendChild(retry);
        } finally {
            moreButton.disabled = false;
        }
    }
    moreButton.addEventListener("click", () => load(false));
    return load;
}

function openActivityDialog(note) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Activity");
        const body = document.createElement("div");
        body.className = "modal-form";
        body.innerHTML = `
            <p class="modal-message share-note-title"></p>
            <p class="share-empty">What was done to this note, newest first. The changed text itself is not recorded here; see History for that.</p>
            <p class="share-feedback" role="alert"></p>
            <ol class="timeline" aria-live="polite"></ol>
            <div class="modal-actions">
                <button type="button" class="secondary hidden" data-action="more">Show older</button>
                <button type="button" class="secondary" data-action="close">Close</button>
            </div>
        `;
        body.querySelector(".share-note-title").textContent = note.title || "";

        let deactivate = () => {};
        const close = (result) => {
            backdrop.remove();
            deactivate();
            resolve(result);
        };
        const load = createAuditPager({
            listEl: body.querySelector(".timeline"),
            moreButton: body.querySelector('[data-action="more"]'),
            feedbackEl: body.querySelector(".share-feedback"),
            url: () => `/api/notes/${note.id}/activity`,
            withIds: false,
            emptyText: "Nothing has been recorded for this note yet.",
            onError: (error) => {
                if (error.status === 404 || error.status === 401) {
                    close(error.status === 404 ? {error} : null);
                    return true;
                }
                return false;
            },
        });

        body.querySelector('[data-action="close"]').addEventListener("click", () => close(null));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(null);
            }
        });
        content.appendChild(body);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, () => close(null));
        body.querySelector('[data-action="close"]').focus();
        load(true);
    });
}

const loadAuditFeed = appDomReady ? createAuditPager({
    listEl: document.getElementById("audit-list"),
    moreButton: document.getElementById("audit-more"),
    feedbackEl: document.getElementById("audit-feedback"),
    url: () => {
        const params = new URLSearchParams();
        const type = document.getElementById("audit-filter-type").value.trim();
        const noteId = document.getElementById("audit-filter-note").value.trim();
        if (type) {
            params.set("type", type);
        }
        if (noteId) {
            params.set("noteId", noteId);
        }
        return `/api/audit-events?${params.toString()}`;
    },
    withIds: true,
    emptyText: "No events match.",
    onError: (error) => error.status === 401,
}) : async () => {};

// ----- Notifications -----

let unreadNotifications = 0;

function setUnreadNotifications(count) {
    unreadNotifications = Number.isFinite(count) && count > 0 ? count : 0;
    notificationsBadgeEl.textContent = unreadNotifications > 99 ? "99+" : String(unreadNotifications);
    notificationsBadgeEl.classList.toggle("hidden", unreadNotifications === 0);
    // The badge is decoration; the count is in the button's name for assistive technology.
    notificationsButtonEl.setAttribute(
        "aria-label",
        unreadNotifications === 0 ? "Notifications" : `Notifications, ${unreadNotifications} unread`,
    );
}

async function refreshUnreadNotifications() {
    if (!getToken()) {
        return;
    }
    try {
        const payload = await api("/api/notifications/unread-count");
        setUnreadNotifications(payload?.unread);
    } catch {
        // The badge keeps its last value; the next refresh tries again.
    }
}

function openNotificationsDialog() {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Notifications");

        const body = document.createElement("div");
        body.className = "modal-form";
        body.innerHTML = `
            <div class="list-meta">
                <span data-role="summary" aria-live="polite"></span>
                <button type="button" class="secondary" data-action="read-all">Mark all as read</button>
            </div>
            <p class="share-feedback" role="alert"></p>
            <ul class="notification-list" data-role="list"></ul>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="more">Show older</button>
                <button type="button" class="secondary" data-action="close">Close</button>
            </div>
        `;
        const listEl = body.querySelector('[data-role="list"]');
        const summaryEl = body.querySelector('[data-role="summary"]');
        const feedbackEl = body.querySelector(".share-feedback");
        const moreButton = body.querySelector('[data-action="more"]');
        const readAllButton = body.querySelector('[data-action="read-all"]');
        let page = 0;
        let closed = false;
        let deactivate = () => {};
        const close = (destination) => {
            if (closed) {
                return;
            }
            closed = true;
            backdrop.remove();
            deactivate();
            resolve(destination);
        };

        const updateSummary = (total, unread) => {
            setUnreadNotifications(unread);
            summaryEl.textContent = total === 0 ? "" : `${unread} unread of ${total}`;
            readAllButton.disabled = unread === 0;
        };

        const addItem = (notification) => {
            const item = document.createElement("li");
            item.className = "notification-item";
            item.classList.toggle("notification-item--unread", !notification.read_at);
            item.innerHTML = `
                <p class="notification-message"></p>
                <small></small>
                <div class="kanban-actions"></div>
            `;
            item.querySelector(".notification-message").textContent = notification.message;
            item.querySelector("small").textContent = `${formatTimestamp(notification.created_at)}${notification.read_at ? "" : " · unread"}`;
            const actions = item.querySelector(".kanban-actions");

            const markRead = async () => {
                if (notification.read_at) {
                    return true;
                }
                try {
                    const updated = await api(`/api/notifications/${notification.id}/read`, {method: "POST"});
                    notification.read_at = updated.read_at;
                    item.classList.remove("notification-item--unread");
                    item.querySelector("small").textContent = formatTimestamp(notification.created_at);
                    actions.querySelector('[data-action="read"]')?.remove();
                    await refreshUnreadNotifications();
                    summaryEl.textContent = summaryEl.textContent.replace(/^\d+/, String(unreadNotifications));
                    readAllButton.disabled = unreadNotifications === 0;
                    return true;
                } catch (error) {
                    setFeedback(feedbackEl, error.status === 404 ? "This notification no longer exists." : error.message, true);
                    return false;
                }
            };

            if (notification.link) {
                const open = document.createElement("button");
                open.type = "button";
                open.className = "secondary";
                open.textContent = notification.resource?.type === "task" ? "Open tasks" : "Open shared notes";
                open.addEventListener("click", async () => {
                    await markRead();
                    close(notification.link);
                });
                actions.appendChild(open);
            }
            if (!notification.read_at) {
                const read = document.createElement("button");
                read.type = "button";
                read.className = "secondary";
                read.dataset.action = "read";
                read.textContent = "Mark as read";
                read.setAttribute("aria-label", `Mark as read: ${notification.message}`);
                read.addEventListener("click", markRead);
                actions.appendChild(read);
            }
            listEl.appendChild(item);
        };

        async function loadMore() {
            moreButton.disabled = true;
            if (page === 0) {
                listEl.innerHTML = `<li class="share-empty">Loading…</li>`;
            }
            try {
                const payload = await api(`/api/notifications?limit=20&page=${page + 1}`);
                if (closed) {
                    return;
                }
                if (page === 0) {
                    listEl.innerHTML = "";
                }
                page += 1;
                const items = Array.isArray(payload?.items) ? payload.items : [];
                items.forEach(addItem);
                if (payload.total === 0) {
                    listEl.innerHTML = `<li class="share-empty">No notifications yet.</li>`;
                }
                updateSummary(payload.total, payload.unread);
                moreButton.classList.toggle("hidden", page * 20 >= payload.total);
                moreButton.disabled = false;
            } catch (error) {
                if (closed || error.status === 401) {
                    close(null);
                    return;
                }
                if (page === 0) {
                    listEl.innerHTML = "";
                }
                feedbackEl.textContent = "";
                setFeedback(feedbackEl, "Could not load notifications. ", true);
                const retry = document.createElement("button");
                retry.type = "button";
                retry.className = "secondary";
                retry.textContent = "Retry";
                retry.addEventListener("click", () => {
                    setFeedback(feedbackEl, "");
                    loadMore();
                });
                feedbackEl.appendChild(retry);
            }
        }

        readAllButton.addEventListener("click", async () => {
            readAllButton.disabled = true;
            try {
                await api("/api/notifications/read-all", {method: "POST"});
                page = 0;
                await loadMore();
            } catch (error) {
                readAllButton.disabled = false;
                setFeedback(feedbackEl, error.message, true);
            }
        });
        moreButton.addEventListener("click", loadMore);
        body.querySelector('[data-action="close"]').addEventListener("click", () => close(null));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(null);
            }
        });

        content.appendChild(body);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, () => close(null));
        body.querySelector('[data-action="close"]').focus();
        loadMore();
    });
}

async function loadNotificationPreferences() {
    setFeedback(notificationPreferencesFeedbackEl, "");
    try {
        const preferences = await api("/api/account/notification-preferences");
        for (const box of notificationPreferencesFormEl.querySelectorAll('input[type="checkbox"]')) {
            box.checked = preferences?.email?.[box.name] !== false;
        }
    } catch {
        setFeedback(notificationPreferencesFeedbackEl, "Could not load your email choices.", true);
    }
}

// ----- Tasks board -----

const TASK_STATUSES = ["backlog", "todo", "in_progress", "done"];
const TASK_STATUS_LABELS = {backlog: "Backlog", todo: "To do", in_progress: "In progress", done: "Done"};
const taskFilterEls = {
    status: document.getElementById("task-filter-status"),
    priority: document.getElementById("task-filter-priority"),
    assignee: document.getElementById("task-filter-assignee"),
};
let allTasks = [];
let draggedTaskId = "";

// Offset of a time zone from UTC at a given moment, in milliseconds.
function zoneOffset(date, timeZone) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat("en-US", {
        timeZone, hourCycle: "h23", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit",
    }).formatToParts(date).map((part) => [part.type, part.value]));
    return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second) - Math.floor(date.getTime() / 1000) * 1000;
}

// "2026-10-20T15:00" as wall-clock time in the account's time zone -> ISO time in UTC.
function zonedInputToUtc(value, timeZone) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value);
    if (!match) {
        return null;
    }
    const wallClock = Date.UTC(match[1], match[2] - 1, match[3], match[4], match[5]);
    let utc = wallClock - zoneOffset(new Date(wallClock), timeZone);
    // The offset at the result can differ from the first guess around a clock change.
    utc = wallClock - zoneOffset(new Date(utc), timeZone);
    return new Date(utc).toISOString().replace(".000Z", "Z");
}

function utcToZonedInput(iso, timeZone) {
    const date = iso ? new Date(iso) : null;
    if (!date || Number.isNaN(date.getTime())) {
        return "";
    }
    return new Date(date.getTime() + zoneOffset(date, timeZone)).toISOString().slice(0, 16);
}

async function loadTasks() {
    setFeedback(tasksFeedbackEl, "");
    tasksBoardEl.setAttribute("aria-busy", "true");
    try {
        const tasks = [];
        for (let page = 1; page <= 50; page += 1) {
            const payload = await api(`/api/tasks?limit=100&page=${page}`);
            tasks.push(...(Array.isArray(payload?.items) ? payload.items : []));
            if (tasks.length >= (payload?.total ?? 0) || !payload?.items?.length) {
                break;
            }
        }
        allTasks = tasks;
        renderTasks();
    } catch (error) {
        if (error.status === 401) {
            return;
        }
        tasksFeedbackEl.textContent = "";
        setFeedback(tasksFeedbackEl, "Could not load the tasks. ", true);
        const retry = document.createElement("button");
        retry.type = "button";
        retry.className = "secondary";
        retry.textContent = "Retry";
        retry.addEventListener("click", loadTasks);
        tasksFeedbackEl.appendChild(retry);
    } finally {
        tasksBoardEl.setAttribute("aria-busy", "false");
    }
}

function taskMatchesFilters(task) {
    const {status, priority, assignee} = taskFilterEls;
    if (status.value && task.status !== status.value) {
        return false;
    }
    if (priority.value && task.priority !== priority.value) {
        return false;
    }
    if (assignee.value === "me") {
        return task.assignee?.id === accountProfile?.id;
    }
    return assignee.value !== "none" || !task.assignee;
}

function renderTasks() {
    for (const column of tasksBoardEl.querySelectorAll(".kanban-column")) {
        const status = column.dataset.status;
        const listEl = column.querySelector('[data-role="list"]');
        const tasks = allTasks.filter((task) => task.status === status && taskMatchesFilters(task));
        column.querySelector('[data-role="count"]').textContent = String(tasks.length);
        column.classList.toggle("hidden", Boolean(taskFilterEls.status.value) && taskFilterEls.status.value !== status);
        listEl.innerHTML = "";
        if (tasks.length === 0) {
            const empty = document.createElement("li");
            empty.className = "share-empty kanban-empty";
            empty.textContent = allTasks.some((task) => task.status === status) ? "No tasks match the filters." : "No tasks.";
            listEl.appendChild(empty);
        }
        for (const task of tasks) {
            listEl.appendChild(buildTaskCard(task));
        }
    }
}

function buildTaskCard(task) {
    const isOwner = task.role === "owner";
    const statusIndex = TASK_STATUSES.indexOf(task.status);
    const card = document.createElement("li");
    card.className = `kanban-card kanban-card--${task.priority}`;
    card.dataset.id = task.id;
    card.draggable = true;
    card.tabIndex = 0;

    const overdue = task.due_at && task.status !== "done" && new Date(task.due_at) < new Date();
    card.innerHTML = `
        <strong class="kanban-title"></strong>
        <p class="kanban-meta"><span class="badge">${escapeHtml(task.priority)}</span><span data-role="due"></span></p>
        <p class="kanban-meta" data-role="people"></p>
        <p class="kanban-meta" data-role="note"></p>
        <div class="kanban-actions"></div>
    `;
    card.querySelector(".kanban-title").textContent = task.title;
    const dueEl = card.querySelector('[data-role="due"]');
    dueEl.textContent = task.due_at ? `Due ${formatTimestamp(task.due_at)}${overdue ? " — overdue" : ""}` : "No due time";
    dueEl.classList.toggle("share-feedback--error", Boolean(overdue));
    card.querySelector('[data-role="people"]').textContent = [
        task.assignee ? `Assigned to ${task.assignee.id === accountProfile?.id ? "me" : task.assignee.email}` : "Unassigned",
        isOwner ? "" : `from ${task.owner.email}`,
    ].filter(Boolean).join(", ");
    card.querySelector('[data-role="note"]').textContent = task.note ? `Note: ${task.note.title}` : "";

    const actions = card.querySelector(".kanban-actions");
    const addButton = (label, ariaLabel, handler, className = "secondary") => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = className;
        button.textContent = label;
        button.setAttribute("aria-label", ariaLabel);
        button.addEventListener("click", handler);
        actions.appendChild(button);
    };
    // Every drag-and-drop move has a button, so the board works from the keyboard.
    for (const [offset, arrow] of [[-1, "←"], [1, "→"]]) {
        const target = TASK_STATUSES[statusIndex + offset];
        if (target && task.allowed_statuses.includes(target)) {
            addButton(arrow, `Move "${task.title}" to ${TASK_STATUS_LABELS[target]}`, () => moveTask(task, target));
        }
    }
    if (isOwner) {
        addButton("↑", `Move "${task.title}" up`, () => shiftTask(task, -1));
        addButton("↓", `Move "${task.title}" down`, () => shiftTask(task, 1));
        addButton("Edit", `Edit "${task.title}"`, () => editTask(task));
        addButton("Delete", `Delete "${task.title}"`, () => deleteTask(task), "danger");
    }

    card.addEventListener("dragstart", (event) => {
        draggedTaskId = task.id;
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", task.id);
        card.classList.add("kanban-card--dragging");
    });
    card.addEventListener("dragend", () => {
        card.classList.remove("kanban-card--dragging");
        for (const column of tasksBoardEl.querySelectorAll(".kanban-column--over")) {
            column.classList.remove("kanban-column--over");
        }
    });

    return card;
}

// After any refused or failed change the board is reloaded, so it always shows what the server has.
async function taskAction(action, successMessage) {
    try {
        await action();
        await loadTasks();
        setFeedback(tasksFeedbackEl, successMessage);
    } catch (error) {
        if (error.status === 401) {
            return;
        }
        await loadTasks();
        setFeedback(tasksFeedbackEl, error.status === 404 ? "This task is no longer available to you." : error.message, true);
    }
}

function moveTask(task, status) {
    return taskAction(
        () => api(`/api/tasks/${task.id}/transition`, {method: "POST", body: JSON.stringify({status, version: task.version})}),
        `"${task.title}" moved to ${TASK_STATUS_LABELS[status]}.`,
    );
}

// Puts a task at an index among the tasks shown in its column and sends the whole column order.
function reorderTask(task, visibleIndex) {
    const column = allTasks.filter((other) => other.status === task.status && other.role === "owner");
    const shown = column.filter((other) => other.id !== task.id && taskMatchesFilters(other));
    const before = shown[visibleIndex];
    const order = column.filter((other) => other.id !== task.id);
    order.splice(before ? order.indexOf(before) : order.length, 0, task);
    if (order.every((other, index) => other.id === column[index].id)) {
        return Promise.resolve();
    }

    return taskAction(
        () => api("/api/tasks/reorder", {method: "PATCH", body: JSON.stringify({status: task.status, ordered_ids: order.map((other) => other.id)})}),
        `"${task.title}" moved.`,
    );
}

function shiftTask(task, direction) {
    const shown = allTasks.filter((other) => other.status === task.status && other.role === "owner" && taskMatchesFilters(other));
    const index = shown.findIndex((other) => other.id === task.id);
    const target = index + direction;
    if (target < 0 || target >= shown.length) {
        return Promise.resolve();
    }
    return reorderTask(task, target);
}

async function deleteTask(task) {
    const confirmed = await openConfirmModal({
        title: "Delete task",
        message: `Delete task "${task.title}"? This cannot be undone.`,
        confirmLabel: "Delete",
    });
    if (confirmed) {
        await taskAction(() => api(`/api/tasks/${task.id}`, {method: "DELETE"}), `"${task.title}" deleted.`);
    }
}

async function editTask(task) {
    const saved = await openTaskEditor(task);
    if (saved) {
        await loadTasks();
        setFeedback(tasksFeedbackEl, task ? `"${saved.title}" saved.` : `"${saved.title}" added to the backlog.`);
    }
}

function openTaskEditor(task) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell(task ? "Edit task" : "New task");

        const form = document.createElement("form");
        form.className = "modal-form";
        form.noValidate = true;
        form.innerHTML = `
            <label>Title <input type="text" name="title" maxlength="150" required></label>
            <label>Description <textarea name="description" rows="4" maxlength="5000"></textarea></label>
            <div class="task-editor-row">
                <label>Priority
                    <select name="priority">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </label>
                <label>Due (<span data-role="zone"></span>)
                    <input type="datetime-local" name="due">
                </label>
            </div>
            <div class="task-editor-row">
                <label>Find a note to link
                    <input type="search" name="note-search" placeholder="Part of the title or text" autocomplete="off">
                </label>
                <label>Linked note
                    <select name="note"><option value="">No note</option></select>
                </label>
            </div>
            <label>Assignee
                <select name="assignee"><option value="">Unassigned</option></select>
            </label>
            <p class="share-feedback share-feedback--error" role="alert"></p>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="cancel">Cancel</button>
                <button type="submit">Save</button>
            </div>
        `;
        const field = (name) => form.elements.namedItem(name);
        const feedback = form.querySelector(".share-feedback");
        const submit = form.querySelector('button[type="submit"]');
        form.querySelector('[data-role="zone"]').textContent = userTimezone;
        field("title").value = task?.title ?? "";
        field("description").value = task?.description ?? "";
        field("priority").value = task?.priority ?? "medium";
        field("due").value = utcToZonedInput(task?.due_at, userTimezone);

        let closed = false;
        let deactivate = () => {};
        const close = (result) => {
            closed = true;
            backdrop.remove();
            deactivate();
            resolve(result);
        };

        // Only people who can see the linked note can be assigned.
        async function loadAssignees(selected) {
            const select = field("assignee");
            const noteId = field("note").value;
            try {
                const payload = await api(`/api/tasks/assignees${noteId ? `?noteId=${noteId}` : ""}`);
                select.innerHTML = `<option value="">Unassigned</option>`;
                for (const person of Array.isArray(payload?.items) ? payload.items : []) {
                    const option = document.createElement("option");
                    option.value = person.id;
                    option.textContent = person.id === accountProfile?.id ? `${person.email} (me)` : person.email;
                    select.appendChild(option);
                }
                select.value = selected;
                if (select.value !== selected) {
                    select.value = "";
                    feedback.textContent = "The previous assignee cannot see this note, so the task is unassigned unless you pick someone else.";
                }
            } catch (error) {
                feedback.textContent = "Could not load who can be assigned.";
            }
        }

        // The note list can be long: the select offers the latest matches of the search box,
        // and always keeps the note that is linked or picked.
        let noteSearchId = 0;
        async function loadNotes(query = "") {
            const select = field("note");
            const searchId = ++noteSearchId;
            const chosen = select.dataset.loaded ? select.value : (task?.note?.id ?? "");
            const chosenTitle = select.selectedOptions[0]?.textContent || task?.note?.title || "";
            let notes = [];
            try {
                const params = new URLSearchParams({scope: "all", limit: "20"});
                if (query) {
                    params.set("q", query);
                }
                notes = await api(`/api/notes?${params.toString()}`);
            } catch {
                // The task can still be saved without changing the link.
            }
            if (closed || searchId !== noteSearchId) {
                return;
            }

            select.innerHTML = `<option value="">No note</option>`;
            const options = Array.isArray(notes) ? notes.map((note) => [note.id, note.title]) : [];
            if (chosen && !options.some(([id]) => id === chosen)) {
                options.unshift([chosen, chosenTitle]);
            }
            for (const [id, title] of options) {
                const option = document.createElement("option");
                option.value = id;
                option.textContent = title;
                select.appendChild(option);
            }
            select.value = chosen;
            select.dataset.loaded = "1";
        }

        field("note-search").addEventListener("input", () => loadNotes(field("note-search").value.trim()));

        field("note").addEventListener("change", () => {
            feedback.textContent = "";
            loadAssignees(field("assignee").value);
        });

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!field("title").value.trim()) {
                feedback.textContent = "Enter a title.";
                field("title").focus();
                return;
            }
            const dueAt = field("due").value ? zonedInputToUtc(field("due").value, userTimezone) : null;
            if (field("due").value && !dueAt) {
                feedback.textContent = "Enter a valid due date and time.";
                field("due").focus();
                return;
            }

            const body = {
                title: field("title").value,
                description: field("description").value,
                priority: field("priority").value,
                due_at: dueAt,
                note_id: field("note").value || null,
                assignee_id: field("assignee").value || null,
            };
            feedback.textContent = "";
            submit.disabled = true;
            try {
                const saved = task
                    ? await api(`/api/tasks/${task.id}`, {method: "PATCH", body: JSON.stringify({...body, version: task.version})})
                    : await api("/api/tasks", {method: "POST", body: JSON.stringify(body)});
                close(saved);
            } catch (error) {
                submit.disabled = false;
                feedback.textContent = error.status === 409
                    ? "This task was changed by someone else. Close this dialog to see the current version, then edit again."
                    : error.message;
            }
        });
        form.querySelector('[data-action="cancel"]').addEventListener("click", () => close(null));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(null);
            }
        });

        content.appendChild(form);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, () => close(null));
        field("title").focus();
        // Saving before the note and assignee choices are loaded would send them as empty.
        submit.disabled = true;
        loadNotes()
            .then(() => (closed ? null : loadAssignees(task?.assignee?.id ?? "")))
            .finally(() => {
                submit.disabled = false;
            });
    });
}

if (tasksBoardEl) {
    for (const column of tasksBoardEl.querySelectorAll(".kanban-column")) {
        column.addEventListener("dragover", (event) => {
            if (draggedTaskId) {
                event.preventDefault();
                column.classList.add("kanban-column--over");
            }
        });
        column.addEventListener("dragleave", (event) => {
            if (!column.contains(event.relatedTarget)) {
                column.classList.remove("kanban-column--over");
            }
        });
        column.addEventListener("drop", (event) => {
            event.preventDefault();
            column.classList.remove("kanban-column--over");
            const task = allTasks.find((other) => other.id === draggedTaskId);
            draggedTaskId = "";
            if (!task) {
                return;
            }
            // Another column: a status change, which the server allows or refuses.
            if (column.dataset.status !== task.status) {
                moveTask(task, column.dataset.status);
                return;
            }
            if (task.role !== "owner") {
                setFeedback(tasksFeedbackEl, "Only the owner of a task can change its place in the column.", true);
                return;
            }
            // Same column: drop before the card under the pointer, or at the end.
            const cards = Array.from(column.querySelectorAll(".kanban-card")).filter((card) => card.dataset.id !== task.id);
            const below = cards.findIndex((card) => {
                const box = card.getBoundingClientRect();
                return event.clientY < box.top + box.height / 2;
            });
            const shownOwned = cards.filter((card) => allTasks.find((other) => other.id === card.dataset.id)?.role === "owner");
            const index = below === -1 ? shownOwned.length : shownOwned.indexOf(cards.slice(below).find((card) => shownOwned.includes(card)) ?? null);
            reorderTask(task, index === -1 ? shownOwned.length : index);
        });
    }
}

const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;
const ATTACHMENT_EXTENSIONS = ["png", "jpg", "jpeg", "pdf", "txt", "csv"];

function formatBytes(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    return bytes < 1024 * 1024 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

// fetch() cannot report upload progress, so uploads go through XMLHttpRequest.
function uploadAttachment(noteId, file, onProgress) {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const form = new FormData();
        form.append("file", file);

        request.open("POST", `/api/notes/${noteId}/attachments`);
        request.setRequestHeader("Accept", "application/json");
        request.setRequestHeader("Authorization", `Bearer ${getToken() || ""}`);
        request.upload.addEventListener("progress", (event) => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        });
        request.addEventListener("load", () => {
            let payload = {};
            try {
                payload = JSON.parse(request.responseText);
            } catch {
                payload = {};
            }
            if (request.status >= 200 && request.status < 300) {
                resolve(payload);
                return;
            }
            if (request.status === 401) {
                signOutLocally("Your session has ended. Sign in again.", true);
            }
            reject(apiError(
                extractValidationMessage(payload) || payload.error || payload.message || `Upload failed: ${request.status}`,
                request.status,
            ));
        });
        request.addEventListener("error", () => reject(apiError("The upload was interrupted. Check your connection and try again.", 0)));
        request.addEventListener("abort", () => reject(apiError("Upload cancelled.", 0)));
        request.send(form);
    });
}

async function downloadAttachment(attachment) {
    const response = await fetch(attachment.download_url, {headers: {Authorization: `Bearer ${getToken() || ""}`}});
    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw apiError(payload.error || payload.message || `Download failed: ${response.status}`, response.status);
    }

    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement("a");
    link.href = url;
    link.download = attachment.filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// Files of one note: everyone who sees the note can download; owner and editors can add and delete.
function openAttachmentsDialog(note, canEdit) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell("Files");
        const inputId = `attachment-input-${modalCounter}`;

        const body = document.createElement("div");
        body.className = "modal-form";
        body.innerHTML = `
            <p class="modal-message share-note-title"></p>
            ${canEdit ? `
                <div class="drop-zone" data-role="drop-zone">
                    <label for="${inputId}">Drop files here or choose them</label>
                    <input type="file" id="${inputId}" multiple accept=".png,.jpg,.jpeg,.pdf,.txt,.csv" aria-describedby="${inputId}-hint">
                    <small id="${inputId}-hint">PNG, JPG, PDF, TXT or CSV, up to 10 MB each, at most 5 per note.</small>
                </div>
                <div class="upload-progress hidden" data-role="progress">
                    <span data-role="progress-label"></span>
                    <progress max="100" value="0" aria-label="Upload progress"></progress>
                </div>
            ` : ""}
            <p class="share-feedback" role="alert"></p>
            <h4 class="share-heading">Attached files</h4>
            <div class="share-list" data-role="list" aria-live="polite"></div>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="close">Close</button>
            </div>
        `;
        body.querySelector(".share-note-title").textContent = note.title || "";

        const listEl = body.querySelector('[data-role="list"]');
        const feedbackEl = body.querySelector(".share-feedback");
        const dropZone = body.querySelector('[data-role="drop-zone"]');
        const fileInput = body.querySelector('input[type="file"]');
        const progressEl = body.querySelector('[data-role="progress"]');

        let closed = false;
        let uploading = false;
        let attachmentCount = 0;
        let maxAttachments = 5;
        let deactivate = () => {};
        const close = (result) => {
            if (closed) {
                return;
            }
            closed = true;
            backdrop.remove();
            deactivate();
            resolve(result);
        };

        // The note is gone or no longer shared with us.
        const closeIfAccessLost = (error) => {
            if (error?.status === 404 && error.message === "Note not found.") {
                close({error});
                return true;
            }
            return false;
        };

        const render = (items) => {
            listEl.innerHTML = "";
            if (items.length === 0) {
                listEl.innerHTML = `<p class="share-empty">No files attached.</p>`;
                return;
            }

            for (const attachment of items) {
                const row = document.createElement("div");
                row.className = "share-row attachment-row";
                row.innerHTML = `
                    <span class="share-email"></span>
                    <button type="button" class="secondary" data-action="download">Download</button>
                    ${canEdit ? `<button type="button" class="danger" data-action="delete">Delete</button>` : ""}
                `;
                row.querySelector(".share-email").textContent = `${attachment.filename} (${formatBytes(attachment.size)})`;
                const downloadButton = row.querySelector('[data-action="download"]');
                downloadButton.setAttribute("aria-label", `Download ${attachment.filename}`);
                downloadButton.addEventListener("click", async () => {
                    downloadButton.disabled = true;
                    try {
                        await downloadAttachment(attachment);
                        setFeedback(feedbackEl, "");
                    } catch (error) {
                        setFeedback(feedbackEl, error.status === 404 ? "This file is no longer available." : error.message, true);
                        await load();
                    } finally {
                        downloadButton.disabled = false;
                    }
                });

                const deleteButton = row.querySelector('[data-action="delete"]');
                deleteButton?.setAttribute("aria-label", `Delete ${attachment.filename}`);
                deleteButton?.addEventListener("click", async () => {
                    const confirmed = await openConfirmModal({
                        title: "Delete file",
                        message: `Delete "${attachment.filename}"? This cannot be undone.`,
                        confirmLabel: "Delete",
                    });
                    if (!confirmed || closed) {
                        return;
                    }
                    try {
                        await api(`/api/attachments/${attachment.id}`, {method: "DELETE"});
                        setFeedback(feedbackEl, `Deleted "${attachment.filename}".`);
                    } catch (error) {
                        setFeedback(feedbackEl, error.status === 404 ? "This file was already deleted." : error.message, true);
                    }
                    await load();
                });

                listEl.appendChild(row);
            }
        };

        async function load() {
            if (closed) {
                return;
            }
            if (listEl.childElementCount === 0) {
                listEl.innerHTML = `<p class="share-empty">Loading…</p>`;
            }
            listEl.setAttribute("aria-busy", "true");
            try {
                const payload = await api(`/api/notes/${note.id}/attachments`);
                const items = Array.isArray(payload?.items) ? payload.items : [];
                attachmentCount = items.length;
                maxAttachments = Number.isFinite(payload?.max) ? payload.max : 5;
                if (!closed) {
                    render(items);
                }
            } catch (error) {
                if (closed || closeIfAccessLost(error)) {
                    return;
                }
                listEl.innerHTML = `
                    <p class="share-empty share-feedback--error">Could not load the files.</p>
                    <button type="button" class="secondary" data-action="retry">Retry</button>
                `;
                listEl.querySelector('[data-action="retry"]').addEventListener("click", load);
            } finally {
                listEl.setAttribute("aria-busy", "false");
            }
        }

        // Checked here for a quick answer; the server checks the real content again.
        const problemWith = (file) => {
            const extension = (file.name.split(".").pop() || "").toLowerCase();
            if (!file.name.includes(".") || !ATTACHMENT_EXTENSIONS.includes(extension)) {
                return "only PNG, JPG, PDF, TXT and CSV files are allowed";
            }
            if (file.size > ATTACHMENT_MAX_BYTES) {
                return "larger than 10 MB";
            }
            return file.size === 0 ? "the file is empty" : "";
        };

        async function uploadFiles(files) {
            if (uploading || files.length === 0) {
                return;
            }
            uploading = true;
            fileInput.disabled = true;
            const problems = [];
            let uploaded = 0;

            for (const [index, file] of files.entries()) {
                const problem = attachmentCount >= maxAttachments ? `a note can have at most ${maxAttachments} files` : problemWith(file);
                if (problem) {
                    problems.push(`${file.name}: ${problem}`);
                    continue;
                }

                progressEl.classList.remove("hidden");
                progressEl.querySelector('[data-role="progress-label"]').textContent = `Uploading ${file.name} (${index + 1} of ${files.length})`;
                const bar = progressEl.querySelector("progress");
                bar.value = 0;
                try {
                    await uploadAttachment(note.id, file, (percent) => {
                        bar.value = percent;
                    });
                    uploaded += 1;
                    attachmentCount += 1;
                } catch (error) {
                    if (closeIfAccessLost(error) || error.status === 401) {
                        close(error.status === 401 ? null : {error});
                        return;
                    }
                    problems.push(`${file.name}: ${retryHint(error).replace(/^file: /, "").replace(/\.$/, "")}`);
                }
            }

            uploading = false;
            fileInput.disabled = false;
            fileInput.value = "";
            progressEl.classList.add("hidden");
            if (problems.length > 0) {
                setFeedback(feedbackEl, `${uploaded > 0 ? `${uploaded} uploaded. ` : ""}Not uploaded — ${problems.join("; ")}.`, true);
            } else {
                setFeedback(feedbackEl, `${uploaded} file${uploaded === 1 ? "" : "s"} uploaded.`);
            }
            await load();
        }

        if (canEdit) {
            fileInput.addEventListener("change", () => uploadFiles(Array.from(fileInput.files || [])));
            for (const eventName of ["dragenter", "dragover"]) {
                dropZone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    dropZone.classList.add("drop-zone--active");
                });
            }
            for (const eventName of ["dragleave", "drop"]) {
                dropZone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    dropZone.classList.remove("drop-zone--active");
                });
            }
            dropZone.addEventListener("drop", (event) => uploadFiles(Array.from(event.dataTransfer?.files || [])));
        }

        body.querySelector('[data-action="close"]').addEventListener("click", () => close(null));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(null);
            }
        });

        content.appendChild(body);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, () => close(null));
        (fileInput || body.querySelector('[data-action="close"]')).focus();
        load();
    });
}

function openTaxonomyItemModal(one, item) {
    return new Promise((resolve) => {
        const {backdrop, content} = buildModalShell(`Edit ${one}`);

        const form = document.createElement("form");
        form.className = "modal-form";
        form.noValidate = true;
        form.innerHTML = `
            <label>Name <input type="text" name="name" maxlength="30" autocomplete="off" required></label>
            <label class="color-toggle"><input type="checkbox" name="use-color"> Color</label>
            <label class="color-input">Color value <input type="color" name="color"></label>
            <p class="share-feedback share-feedback--error" role="alert"></p>
            <div class="modal-actions">
                <button type="button" class="secondary" data-action="cancel">Cancel</button>
                <button type="submit">Save</button>
            </div>
        `;
        const nameInput = form.querySelector('input[name="name"]');
        const useColor = form.querySelector('input[name="use-color"]');
        const colorInput = form.querySelector('input[name="color"]');
        nameInput.value = item.name;
        useColor.checked = Boolean(item.color);
        colorInput.value = item.color || "#b8743f";
        colorInput.disabled = !useColor.checked;
        useColor.addEventListener("change", () => {
            colorInput.disabled = !useColor.checked;
        });

        let deactivate = () => {};
        const close = (result) => {
            backdrop.remove();
            deactivate();
            resolve(result);
        };

        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!nameInput.value.trim()) {
                form.querySelector(".share-feedback").textContent = "Enter a name.";
                nameInput.focus();
                return;
            }
            close({name: nameInput.value, color: useColor.checked ? colorInput.value : null, version: item.version});
        });
        form.querySelector('[data-action="cancel"]').addEventListener("click", () => close(null));
        backdrop.addEventListener("click", (event) => {
            if (event.target === backdrop) {
                close(null);
            }
        });

        content.appendChild(form);
        document.body.appendChild(backdrop);
        deactivate = activateDialog(backdrop, () => close(null));
        nameInput.focus();
        nameInput.select();
    });
}

// One panel of the "Tags and categories" page: create form plus the list with edit and delete.
function setupTaxonomyPanel(panel) {
    const kind = panel.dataset.taxonomy;
    const one = panel.dataset.one;
    const url = `/api/${kind}`;
    const form = panel.querySelector(".taxonomy-form");
    const nameInput = form.querySelector('input[name="name"]');
    const useColor = form.querySelector('input[name="use-color"]');
    const colorInput = form.querySelector('input[name="color"]');
    const feedbackEl = panel.querySelector(".share-feedback");
    const listEl = panel.querySelector(".taxonomy-list");

    const setFeedback = (message, isError = false) => {
        feedbackEl.textContent = message;
        feedbackEl.classList.toggle("share-feedback--error", Boolean(message) && isError);
        feedbackEl.classList.toggle("share-feedback--ok", Boolean(message) && !isError);
        nameInput.setAttribute("aria-invalid", message && isError ? "true" : "false");
    };

    const render = (items) => {
        listEl.innerHTML = "";
        if (items.length === 0) {
            listEl.innerHTML = `<p class="share-empty">No ${kind} yet.</p>`;
            return;
        }

        for (const item of items) {
            const row = document.createElement("div");
            row.className = "taxonomy-row";
            row.innerHTML = `
                <span class="taxonomy-name">${colorDotHtml(item.color)}<span></span></span>
                <button type="button" class="secondary" data-action="edit">Edit</button>
                <button type="button" class="danger" data-action="delete">Delete</button>
            `;
            row.querySelector(".taxonomy-name span:last-child").textContent = item.name;
            const editButton = row.querySelector('[data-action="edit"]');
            const deleteButton = row.querySelector('[data-action="delete"]');
            editButton.setAttribute("aria-label", `Edit ${one} ${item.name}`);
            deleteButton.setAttribute("aria-label", `Delete ${one} ${item.name}`);

            editButton.addEventListener("click", async () => {
                const changes = await openTaxonomyItemModal(one, item);
                if (!changes) {
                    return;
                }
                try {
                    await api(`${url}/${item.id}`, {method: "PATCH", body: JSON.stringify(changes)});
                    await load();
                    setFeedback(`Saved ${one} "${changes.name.trim()}".`);
                } catch (error) {
                    await load();
                    setFeedback(error.message, true);
                }
            });

            deleteButton.addEventListener("click", async () => {
                const confirmed = await openConfirmModal({
                    title: `Delete ${one}`,
                    message: `Delete ${one} "${item.name}"? Notes that use it are kept and only lose this ${one}.`,
                    confirmLabel: "Delete",
                });
                if (!confirmed) {
                    return;
                }
                try {
                    await api(`${url}/${item.id}`, {method: "DELETE"});
                    await load();
                    setFeedback(`Deleted ${one} "${item.name}".`);
                } catch (error) {
                    await load();
                    setFeedback(error.status === 404 ? `This ${one} was already deleted.` : error.message, true);
                }
            });

            listEl.appendChild(row);
        }
    };

    async function load() {
        if (listEl.childElementCount === 0) {
            listEl.innerHTML = `<p class="share-empty">Loading…</p>`;
        }
        listEl.setAttribute("aria-busy", "true");
        try {
            const payload = await api(`${url}?itemsPerPage=100`);
            render(Array.isArray(payload?.items) ? payload.items : []);
        } catch {
            listEl.innerHTML = `
                <p class="share-empty share-feedback--error">Could not load ${kind}.</p>
                <button type="button" class="secondary" data-action="retry">Retry</button>
            `;
            listEl.querySelector('[data-action="retry"]').addEventListener("click", load);
        } finally {
            listEl.setAttribute("aria-busy", "false");
        }
    }

    useColor.addEventListener("change", () => {
        colorInput.disabled = !useColor.checked;
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const name = nameInput.value.trim();
        if (!name) {
            setFeedback(`Enter a name for the ${one}.`, true);
            nameInput.focus();
            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        try {
            await api(url, {
                method: "POST",
                body: JSON.stringify({name, color: useColor.checked ? colorInput.value : null}),
            });
            form.reset();
            colorInput.disabled = true;
            await load();
            setFeedback(`Added ${one} "${name}".`);
        } catch (error) {
            setFeedback(error.message, true);
        } finally {
            submitButton.disabled = false;
            nameInput.focus();
        }
    });

    return {
        load() {
            setFeedback("");
            return load();
        },
    };
}

const taxonomyPanels = appDomReady
    ? Array.from(taxonomyView.querySelectorAll("[data-taxonomy]")).map(setupTaxonomyPanel)
    : [];

function loadTaxonomyPage() {
    return Promise.all(taxonomyPanels.map((panel) => panel.load()));
}

// Options of the "Tag" filter above the notes list and of the bulk toolbar: the signed-in user's own taxonomy.
async function refreshTagFilter() {
    const previous = tagFilterEl.value;
    try {
        const payload = await api("/api/tags?itemsPerPage=100");
        const categories = await api("/api/categories?itemsPerPage=100");
        tagFilterEl.innerHTML = `<option value="">All tags</option>`;
        bulkTagEl.innerHTML = "";
        bulkCategoryEl.innerHTML = `<option value="">No category</option>`;
        for (const tag of Array.isArray(payload?.items) ? payload.items : []) {
            const option = document.createElement("option");
            option.value = tag.id;
            option.textContent = tag.name;
            tagFilterEl.appendChild(option);
            bulkTagEl.appendChild(option.cloneNode(true));
        }
        for (const category of Array.isArray(categories?.items) ? categories.items : []) {
            const option = document.createElement("option");
            option.value = category.id;
            option.textContent = category.name;
            bulkCategoryEl.appendChild(option);
        }
        tagFilterEl.value = previous;
        if (tagFilterEl.value !== previous) {
            tagFilterEl.value = "";
        }
    } catch {
        // The list still works without the filter options.
    }
}

function renderNotesError() {
    notesListEl.innerHTML = `
        <div class="panel notes-error">
            <p>Could not load notes.</p>
            <button type="button" class="secondary" data-action="retry">Retry</button>
        </div>
    `;
    notesListEl.querySelector('[data-action="retry"]').addEventListener("click", () => {
        refreshNotes().then(() => setStatus("")).catch((error) => setStatus(error.message, true));
    });
}

async function refreshNotes() {
    const requestId = ++notesRequestId;
    writeFiltersToUrl();
    renderActiveFilters();

    if (isDateRangeInverted()) {
        notesListEl.innerHTML = `<div class="panel notes-error"><p>"Updated from" is later than "Updated to". Change one of the dates.</p></div>`;
        updatedToEl.setAttribute("aria-invalid", "true");
        throw new Error('"Updated from" must not be later than "Updated to".');
    }
    updatedToEl.removeAttribute("aria-invalid");

    const params = buildNotesSearchParams();
    if (notesListEl.childElementCount === 0) {
        notesListEl.innerHTML = `<div class="panel">Loading…</div>`;
    }
    notesListEl.setAttribute("aria-busy", "true");

    let result;
    try {
        result = await api(`/api/notes?${params.toString()}`, {withHeaders: true});
    } catch (error) {
        if (requestId === notesRequestId) {
            notesListEl.setAttribute("aria-busy", "false");
            renderNotesError();
        }
        throw error;
    }

    // A newer request (another page, scope or search) has been issued meanwhile.
    if (requestId !== notesRequestId) {
        return;
    }
    notesListEl.setAttribute("aria-busy", "false");

    const pageSize = Number.parseInt(pageSizeEl.value, 10) || 10;
    const headerTotal = Number.parseInt(result.headers?.get("X-Total-Count") ?? "", 10);
    notesCache = Array.isArray(result.data) ? result.data : [];
    totalItems = Number.isFinite(headerTotal) ? headerTotal : notesCache.length;
    totalPages = Math.max(1, Math.ceil(totalItems / pageSize));

    // The last page can vanish under us (a filter got narrower, notes were deleted): show the new last one.
    if (currentPage > totalPages) {
        currentPage = totalPages;
        await refreshNotes();
        return;
    }

    hasPrevPage = currentPage > 1;
    hasNextPage = currentPage < totalPages;

    renderNotes();
}

async function refreshProfile() {
    const me = await api("/api/auth/me");
    setProfile(me);
    await loadSettings();
    // The Audit page is offered to administrators; the server decides who really gets the feed.
    try {
        const profile = await api("/api/account/profile");
        navAuditButtonEl.classList.toggle("hidden", profile?.is_admin !== true);
    } catch {
        navAuditButtonEl.classList.add("hidden");
    }
}

async function ensureAuthenticated() {
    await refreshProfile();
}

async function enterNotesAfterAuth(successMessage) {
    navigateTo(PATH_NOTES);
    showAccountView(PATH_NOTES);

    try {
        await refreshProfile();
        await refreshTagFilter();
        await refreshNotes();
        setStatus(successMessage);
    } catch (error) {
        const message = error instanceof Error ? error.message : "Failed to load account data.";
        setStatus(message, true);
    }
}

async function tryConfirmFromLink() {
    const url = new URL(window.location.href);
    const email = (url.searchParams.get("confirm_email") || "").trim();
    const code = (url.searchParams.get("confirm_code") || "").trim();

    if (!email || !code) {
        return false;
    }

    try {
        const result = await api("/api/auth/confirm", {
            method: "POST",
            body: JSON.stringify({email, code}),
        });
        setToken(result.token);
        clearEmailConfirmationParamsFromUrl();
        await enterNotesAfterAuth("Account confirmed.");
    } catch (error) {
        clearEmailConfirmationParamsFromUrl();
        showAuthUI();
        setStatus(error.message, true);
    }

    return true;
}

// A reset link shows the "Reset Password" form; the token is kept out of the address bar.
function tryResetFromLink() {
    const token = (new URL(window.location.href).searchParams.get("reset_token") || "").trim();
    if (!token) {
        return false;
    }

    pendingResetToken = token;
    clearEmailConfirmationParamsFromUrl();
    clearToken();
    showAuthUI();
    showAuthCard("reset");
    resetFormEl.reset();
    resetFormEl.classList.remove("hidden");
    resetRequestNewEl.classList.add("hidden");
    setFeedback(resetFeedbackEl, "");
    setStatus("Choose a new password.");
    document.getElementById("reset-password").focus();

    return true;
}

async function tryEmailChangeFromLink() {
    const token = (new URL(window.location.href).searchParams.get("email_change_token") || "").trim();
    if (!token) {
        return false;
    }

    clearEmailConfirmationParamsFromUrl();
    try {
        const result = await api("/api/account/email/confirm", {method: "POST", anonymous: true, body: JSON.stringify({token})});
        signOutLocally(result.message || "Email address changed. Sign in with your new email.");
    } catch (error) {
        setStatus(retryHint(error), true);
        return false;
    }

    return true;
}

async function renderRoute() {
    const linkConfirmed = await tryConfirmFromLink();
    if (linkConfirmed) {
        return;
    }

    if (tryResetFromLink() || await tryEmailChangeFromLink()) {
        return;
    }

    const token = getToken();
    const path = currentPath();
    const accountPath = accountPathFor(path);

    if (!token) {
        showAuthUI();
        return;
    }

    try {
        await ensureAuthenticated();
    } catch {
        clearToken();
        showAuthUI();
        setProfile(null);
        return;
    }

    showAccountView(accountPath);
    if (accountPath === PATH_PROFILE) {
        await loadAccountPage();
    } else if (accountPath === PATH_TASKS) {
        await loadTasks();
    } else if (accountPath === PATH_AUDIT) {
        await loadAuditFeed(true);
    } else if (accountPath === PATH_TAGS) {
        await loadTaxonomyPage();
    } else if (accountPath !== PATH_PROFILE) {
        notesListEl.innerHTML = "";
        await refreshTagFilter();
        readFiltersFromUrl();
        await refreshNotes().catch((error) => setStatus(error.message, true));
    }
}

if (!appDomReady) {
    console.warn("App UI is not mounted on this page; skipping app.js bootstrap.");
} else {
signupFormEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    const email = document.getElementById("signup-email").value;
    const password = document.getElementById("signup-password").value;

    try {
        await api("/api/auth/signup", {
            method: "POST",
            body: JSON.stringify({email, password}),
        });
        const safeEmail = escapeHtml(email);
        const mailhogUrl = "http://localhost:8025";
        setStatus(
            `Sign up request sent to ${safeEmail}. Check your inbox and MailHog at <a href="${mailhogUrl}" target="_blank" rel="noopener noreferrer">${mailhogUrl}</a>.`,
            false,
            true,
        );
    } catch (error) {
        setStatus(error.message, true);
    }
});

signinFormEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    const email = document.getElementById("signin-email").value;
    const password = document.getElementById("signin-password").value;

    try {
        const result = await api("/api/auth/signin", {
            method: "POST",
            body: JSON.stringify({email, password}),
        });
        setToken(result.token);
        await enterNotesAfterAuth("Signed in.");
    } catch (error) {
        setStatus(error.message, true);
    }
});

createNoteFormEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    const title = document.getElementById("note-title").value;
    const content = document.getElementById("note-content").value;

    try {
        await api("/api/notes", {
            method: "POST",
            body: JSON.stringify({title, content}),
        });
        event.target.reset();
        await refreshNotes();
        setStatus("Note created.");
    } catch (error) {
        setStatus(error.message, true);
    }
});

logoutButtonEl.addEventListener("click", () => {
    navAuditButtonEl.classList.add("hidden");
    setUnreadNotifications(0);
    selectedNotes.clear();
    bulkReportEl.classList.add("hidden");
    userTimezone = "UTC";
    clearToken();
    notesCache = [];
    totalItems = 0;
    totalPages = 1;
    hasNextPage = false;
    hasPrevPage = false;
    currentPage = 1;
    notesListEl.innerHTML = "";
    setProfile(null);
    navigateTo("/");
    showAuthUI();
    setStatus("Logged out.");
});

async function openNotesList(path) {
    navigateTo(path);
    showAccountView(path);
    currentPage = 1;
    notesListEl.innerHTML = "";
    await refreshTagFilter();
    await refreshNotes().catch((error) => setStatus(error.message, true));
}

navNotesButtonEl.addEventListener("click", () => openNotesList(PATH_NOTES));
navSharedButtonEl.addEventListener("click", () => openNotesList(PATH_SHARED));
navTagsButtonEl.addEventListener("click", async () => {
    navigateTo(PATH_TAGS);
    showAccountView(PATH_TAGS);
    await loadTaxonomyPage();
});
navAuditButtonEl.addEventListener("click", async () => {
    navigateTo(PATH_AUDIT);
    showAccountView(PATH_AUDIT);
    await loadAuditFeed(true);
});
document.getElementById("audit-filter-form").addEventListener("submit", (event) => {
    event.preventDefault();
    loadAuditFeed(true);
});
document.getElementById("audit-filter-reset").addEventListener("click", () => {
    document.getElementById("audit-filter-form").reset();
    loadAuditFeed(true);
});
notificationsButtonEl.addEventListener("click", async () => {
    const destination = await openNotificationsDialog();
    await refreshUnreadNotifications();
    if (destination === PATH_TASKS) {
        navTasksButtonEl.click();
    } else if (destination === PATH_SHARED) {
        navSharedButtonEl.click();
    }
});
notificationPreferencesFormEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    const email = {};
    for (const box of notificationPreferencesFormEl.querySelectorAll('input[type="checkbox"]')) {
        email[box.name] = box.checked;
    }
    const submit = notificationPreferencesFormEl.querySelector("button");
    submit.disabled = true;
    try {
        await api("/api/account/notification-preferences", {method: "PATCH", body: JSON.stringify({email})});
        setFeedback(notificationPreferencesFeedbackEl, "Email choices saved.");
    } catch (error) {
        setFeedback(notificationPreferencesFeedbackEl, error.message, true);
    } finally {
        submit.disabled = false;
    }
});
setInterval(() => {
    if (document.visibilityState === "visible" && !accountSection.classList.contains("hidden")) {
        refreshUnreadNotifications();
    }
}, 60000);
navTasksButtonEl.addEventListener("click", async () => {
    navigateTo(PATH_TASKS);
    showAccountView(PATH_TASKS);
    await loadTasks();
});
document.getElementById("task-new-button").addEventListener("click", () => editTask(null));
for (const filterEl of Object.values(taskFilterEls)) {
    filterEl.addEventListener("change", renderTasks);
}
tagFilterEl.addEventListener("change", () => {
    currentPage = 1;
    refreshNotes().catch((error) => setStatus(error.message, true));
});

navProfileButtonEl.addEventListener("click", async () => {
    navigateTo(PATH_PROFILE);
    showAccountView(PATH_PROFILE);
    await loadAccountPage();
});

forgotButtonEl.addEventListener("click", () => {
    showAuthCard("forgot");
    setFeedback(forgotFeedbackEl, "");
    const email = document.getElementById("forgot-email");
    email.value = document.getElementById("signin-email").value;
    email.focus();
});

forgotFormEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    const emailInput = document.getElementById("forgot-email");
    if (!emailInput.value.trim() || !emailInput.checkValidity()) {
        setFeedback(forgotFeedbackEl, "Enter a valid email address.", true);
        emailInput.focus();
        return;
    }

    const submit = forgotFormEl.querySelector("button");
    submit.disabled = true;
    try {
        const result = await api("/api/auth/password-reset/request", {
            method: "POST",
            anonymous: true,
            body: JSON.stringify({email: emailInput.value.trim()}),
        });
        setFeedback(forgotFeedbackEl, result.message || "If an account exists for this email, a password reset link has been sent.");
    } catch (error) {
        setFeedback(forgotFeedbackEl, retryHint(error), true);
    } finally {
        submit.disabled = false;
    }
});

resetFormEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    const password = document.getElementById("reset-password");
    const problem = passwordFormProblem(password.value, document.getElementById("reset-password-repeat").value);
    if (problem) {
        setFeedback(resetFeedbackEl, problem, true);
        password.focus();
        return;
    }

    const submit = resetFormEl.querySelector("button");
    submit.disabled = true;
    try {
        const result = await api("/api/auth/password-reset/confirm", {
            method: "POST",
            anonymous: true,
            body: JSON.stringify({token: pendingResetToken, password: password.value}),
        });
        pendingResetToken = "";
        resetFormEl.reset();
        showAuthCard(null);
        setStatus(result.message || "Password updated. Sign in with your new password.");
        document.getElementById("signin-email").focus();
    } catch (error) {
        const aboutLink = /link/i.test(error.message);
        setFeedback(resetFeedbackEl, retryHint(error).replace(/^token: /, ""), true);
        // An expired or used link cannot be retried: offer a new one instead of the form.
        if (error.status === 422 && aboutLink) {
            resetFormEl.classList.add("hidden");
            resetRequestNewEl.classList.remove("hidden");
            resetRequestNewEl.focus();
        }
    } finally {
        submit.disabled = false;
    }
});

resetRequestNewEl.addEventListener("click", () => {
    pendingResetToken = "";
    showAuthCard("forgot");
    setFeedback(forgotFeedbackEl, "");
    document.getElementById("forgot-email").focus();
});

changeEmailFormEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    const emailInput = document.getElementById("change-email-new");
    const passwordInput = document.getElementById("change-email-password");
    if (!emailInput.value.trim() || !emailInput.checkValidity()) {
        setFeedback(changeEmailFeedbackEl, "Enter a valid email address.", true);
        emailInput.focus();
        return;
    }
    if (!passwordInput.value) {
        setFeedback(changeEmailFeedbackEl, "Enter your current password.", true);
        passwordInput.focus();
        return;
    }

    const submit = changeEmailFormEl.querySelector("button");
    submit.disabled = true;
    try {
        const profile = await api("/api/account/profile", {
            method: "PATCH",
            body: JSON.stringify({email: emailInput.value.trim(), current_password: passwordInput.value}),
        });
        changeEmailFormEl.reset();
        setProfile(profile);
        setFeedback(changeEmailFeedbackEl, `Confirmation link sent to ${profile.pending_email}. Your email changes when you open it.`);
    } catch (error) {
        setFeedback(changeEmailFeedbackEl, retryHint(error), true);
    } finally {
        submit.disabled = false;
    }
});

settingsFormEl.addEventListener("submit", async (event) => {
    event.preventDefault();
    const submit = settingsFormEl.querySelector("button");
    submit.disabled = true;
    try {
        const settings = await api("/api/account/settings", {
            method: "PATCH",
            body: JSON.stringify({timezone: settingsTimezoneEl.value}),
        });
        userTimezone = settings.timezone;
        setFeedback(settingsFeedbackEl, `Settings saved. Times are shown in ${userTimezone}.`);
    } catch (error) {
        setFeedback(settingsFeedbackEl, error.message, true);
    } finally {
        submit.disabled = false;
    }
});

changePasswordButtonEl.addEventListener("click", async () => {
    if (await openChangePasswordDialog()) {
        setStatus("Password changed. Other devices have been signed out.");
    }
});

searchNotesFormEl.addEventListener("submit", (event) => {
    event.preventDefault();
    currentPage = 1;
    refreshNotes().catch((error) => setStatus(error.message, true));
});

searchQueryEl.addEventListener("input", () => {
    currentPage = 1;
    refreshNotes().catch((error) => setStatus(error.message, true));
});
searchFieldEl.addEventListener("change", () => {
    currentPage = 1;
    refreshNotes().catch((error) => setStatus(error.message, true));
});
searchSortEl.addEventListener("change", () => {
    currentPage = 1;
    refreshNotes().catch((error) => setStatus(error.message, true));
});
pageSizeEl.addEventListener("change", () => {
    currentPage = 1;
    refreshNotes().catch((error) => setStatus(error.message, true));
});
for (const dateEl of [updatedFromEl, updatedToEl]) {
    dateEl.addEventListener("change", () => {
        currentPage = 1;
        refreshNotes().then(() => setStatus("")).catch((error) => setStatus(error.message, true));
    });
}
resetFiltersEl.addEventListener("click", resetFilters);

bulkSelectPageEl.addEventListener("change", () => {
    for (const note of notesCache) {
        if (bulkSelectPageEl.checked) {
            selectedNotes.set(note.id, note.title || "");
        } else {
            selectedNotes.delete(note.id);
        }
    }
    for (const box of notesListEl.querySelectorAll('[data-action="select"]')) {
        box.checked = bulkSelectPageEl.checked;
    }
    renderBulkBar();
});
document.getElementById("bulk-clear").addEventListener("click", clearSelection);
document.getElementById("bulk-set-category").addEventListener("click", () => {
    runBulk("setCategory", {category_id: bulkCategoryEl.value || null}, new Map(selectedNotes));
});
for (const [buttonId, operation] of [["bulk-add-tag", "addTags"], ["bulk-remove-tag", "removeTags"]]) {
    document.getElementById(buttonId).addEventListener("click", () => {
        if (!bulkTagEl.value) {
            setStatus("Create a tag on the Tags page first.", true);
            return;
        }
        runBulk(operation, {tag_ids: [bulkTagEl.value]}, new Map(selectedNotes));
    });
}
document.getElementById("bulk-delete").addEventListener("click", async () => {
    const notes = new Map(selectedNotes);
    const confirmed = await openConfirmModal({
        title: "Delete notes",
        message: `Delete ${notes.size} selected note${notes.size === 1 ? "" : "s"}? This cannot be undone. Notes you do not own are skipped.`,
        confirmLabel: `Delete ${notes.size}`,
    });
    if (confirmed) {
        runBulk("delete", {}, notes);
    }
});
prevPageEl.addEventListener("click", () => {
    if (currentPage > 1) {
        currentPage -= 1;
        refreshNotes().catch((error) => setStatus(error.message, true));
    }
});
nextPageEl.addEventListener("click", () => {
    if (hasNextPage || currentPage < totalPages) {
        currentPage += 1;
        refreshNotes().catch((error) => setStatus(error.message, true));
    }
});
window.addEventListener("popstate", () => {
    renderRoute();
});
// Coming back to the tab: roles and shares may have changed in the meantime.
document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && getToken() && isNotesListVisible() && !document.querySelector(".modal-backdrop")) {
        refreshNotes().catch(() => {});
    }
});

renderRoute();
}
