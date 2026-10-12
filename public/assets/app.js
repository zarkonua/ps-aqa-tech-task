const TOKEN_KEY = "qa_task_token";
const PATH_NOTES = "/account/notes";
const PATH_PROFILE = "/account/profile";
const PATH_SHARED = "/account/shared";
const PATH_TAGS = "/account/tags";
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
const tagFilterEl = document.getElementById("notes-tag-filter");
const tagFilterLabelEl = document.getElementById("notes-tag-filter-label");
const updatedFromEl = document.getElementById("notes-updated-from");
const updatedToEl = document.getElementById("notes-updated-to");
const activeFiltersEl = document.getElementById("active-filters");
const activeFilterChipsEl = document.getElementById("active-filter-chips");
const resetFiltersEl = document.getElementById("notes-reset-filters");
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
    tagFilterEl,
    tagFilterLabelEl,
    updatedFromEl,
    updatedToEl,
    activeFiltersEl,
    activeFilterChipsEl,
    resetFiltersEl,
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
    return [PATH_PROFILE, PATH_SHARED, PATH_TAGS].includes(path) ? path : PATH_NOTES;
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

    const isProfile = path === PATH_PROFILE;
    const isShared = path === PATH_SHARED;
    const isTags = path === PATH_TAGS;
    notesView.classList.toggle("hidden", isProfile || isTags);
    profileView.classList.toggle("hidden", !isProfile);
    taxonomyView.classList.toggle("hidden", !isTags);
    createNoteSectionEl.classList.toggle("hidden", isShared);
    // The tag filter offers the signed-in user's own tags; shared notes carry their owner's.
    tagFilterLabelEl.classList.toggle("hidden", isShared);
    notesDashboardEl.classList.toggle("dashboard-grid--single", isShared);
    notesListHeadingEl.textContent = isShared ? "Shared with me" : "List";
    accountTitle.textContent = {[PATH_PROFILE]: "Profile", [PATH_SHARED]: "Shared with me", [PATH_TAGS]: "Tags and categories"}[path] || "Notes";
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
            <h3>${escapeHtml(note.title || "")}</h3>
            ${sharedBy}
            ${taxonomyChipsHtml(note)}
            <p>${escapeHtml(note.content || "")}</p>
            <small>Updated: <time datetime="${escapeHtml(note.updated_at || "")}">${escapeHtml(formatTimestamp(note.updated_at))}</time></small>
            <div class="note-actions">
                ${canEdit ? `<button data-action="edit">Update</button>` : ""}
                ${canEdit ? `<button class="secondary" data-action="tags">Tags</button>` : ""}
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
                await api(`/api/notes/${note.id}`, {
                    method: "PUT",
                    body: JSON.stringify({title: updates.title, content: updates.content}),
                });
                setStatus("Note updated.");
                await refreshNotes();
            } catch (error) {
                await handleNoteError(error);
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

        notesListEl.appendChild(wrapper);
    }
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

// Options of the "Tag" filter above the notes list: the signed-in user's own tags.
async function refreshTagFilter() {
    const previous = tagFilterEl.value;
    try {
        const payload = await api("/api/tags?itemsPerPage=100");
        tagFilterEl.innerHTML = `<option value="">All tags</option>`;
        for (const tag of Array.isArray(payload?.items) ? payload.items : []) {
            const option = document.createElement("option");
            option.value = tag.id;
            option.textContent = tag.name;
            tagFilterEl.appendChild(option);
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
