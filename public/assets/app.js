const TOKEN_KEY = "qa_task_token";
const PATH_NOTES = "/account/notes";
const PATH_PROFILE = "/account/profile";
const PATH_SHARED = "/account/shared";
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

    const token = getToken();
    if (token) {
        headers["Authorization"] = `Bearer ${token}`;
    }

    const response = await fetch(path, {...options, headers});
    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));

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

    return response.json();
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
    return path === PATH_PROFILE || path === PATH_SHARED ? path : PATH_NOTES;
}

function isSharedView() {
    return currentPath() === PATH_SHARED;
}

function isNotesListVisible() {
    return !accountSection.classList.contains("hidden") && !notesView.classList.contains("hidden");
}

function setActiveNav(path) {
    const buttons = [[navNotesButtonEl, PATH_NOTES], [navSharedButtonEl, PATH_SHARED], [navProfileButtonEl, PATH_PROFILE]];
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
    notesView.classList.toggle("hidden", isProfile);
    profileView.classList.toggle("hidden", !isProfile);
    createNoteSectionEl.classList.toggle("hidden", isShared);
    notesDashboardEl.classList.toggle("dashboard-grid--single", isShared);
    notesListHeadingEl.textContent = isShared ? "Shared with me" : "List";
    accountTitle.textContent = isProfile ? "Profile" : (isShared ? "Shared with me" : "Notes");
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

function buildNotesSearchParams() {
    const params = new URLSearchParams();
    const query = searchQueryEl.value.trim();
    const field = searchFieldEl.value;
    const sort = searchSortEl.value;

    if (query) {
        if (field === "title") {
            params.set("title", query);
        } else if (field === "content") {
            params.set("content", query);
        } else {
            params.set("q", query);
        }
    }

    if (sort === "updated_desc") {
        params.set("sort[updatedAt]", "desc");
    } else if (sort === "updated_asc") {
        params.set("sort[updatedAt]", "asc");
    } else if (sort === "title_asc") {
        params.set("sort[title]", "asc");
    } else if (sort === "title_desc") {
        params.set("sort[title]", "desc");
    }

    if (isSharedView()) {
        params.set("scope", "shared");
    }

    params.set("page", String(currentPage));
    params.set("itemsPerPage", pageSizeEl.value);

    return params;
}

function renderNotes() {
    listTotalEl.textContent = `${totalItems} note${totalItems === 1 ? "" : "s"}`;
    pageInfoEl.textContent = Number.isFinite(totalPages) ? `Page ${currentPage} / ${totalPages}` : `Page ${currentPage}`;
    prevPageEl.disabled = !hasPrevPage;
    nextPageEl.disabled = !hasNextPage;

    notesListEl.innerHTML = "";

    if (totalItems === 0) {
        const isSearching = searchQueryEl.value.trim() !== "";
        notesListEl.innerHTML = `<div class="panel">${
            isSharedView() && !isSearching ? "Nothing has been shared with you yet." : "No notes found."
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
            <p>${escapeHtml(note.content || "")}</p>
            <small>Updated: ${escapeHtml(note.updated_at || "-")}</small>
            <div class="note-actions">
                ${canEdit ? `<button data-action="edit">Update</button>` : ""}
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
    const params = buildNotesSearchParams();
    if (notesListEl.childElementCount === 0) {
        notesListEl.innerHTML = `<div class="panel">Loading…</div>`;
    }
    notesListEl.setAttribute("aria-busy", "true");

    let notesPayload;
    try {
        notesPayload = await api(`/api/notes?${params.toString()}`);
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

    hasNextPage = false;
    hasPrevPage = currentPage > 1;

    if (Array.isArray(notesPayload)) {
        notesCache = notesPayload;
        totalItems = notesPayload.length;
        hasNextPage = notesCache.length >= (Number.parseInt(pageSizeEl.value, 10) || 10);
    } else if (notesPayload && typeof notesPayload === "object") {
        if (Array.isArray(notesPayload["hydra:member"])) {
            notesCache = notesPayload["hydra:member"];
        } else if (Array.isArray(notesPayload.member)) {
            notesCache = notesPayload.member;
        } else {
            notesCache = [];
        }

        const hydraTotal = Number.parseInt(String(notesPayload["hydra:totalItems"] ?? ""), 10);
        const plainTotal = Number.parseInt(String(notesPayload.totalItems ?? ""), 10);

        if (Number.isFinite(hydraTotal) && hydraTotal >= 0) {
            totalItems = hydraTotal;
        } else if (Number.isFinite(plainTotal) && plainTotal >= 0) {
            totalItems = plainTotal;
        } else {
            totalItems = notesCache.length;
        }

        const view = notesPayload["hydra:view"] && typeof notesPayload["hydra:view"] === "object"
            ? notesPayload["hydra:view"]
            : null;
        if (view) {
            hasNextPage = typeof view["hydra:next"] === "string" && view["hydra:next"] !== "";
            hasPrevPage = typeof view["hydra:previous"] === "string" && view["hydra:previous"] !== "";
        } else {
            hasNextPage = notesCache.length >= (Number.parseInt(pageSizeEl.value, 10) || 10);
        }
    } else {
        notesCache = [];
        totalItems = 0;
        hasNextPage = false;
        hasPrevPage = currentPage > 1;
    }

    const pageSize = Number.parseInt(pageSizeEl.value, 10) || 10;
    if (totalItems > notesCache.length || (!hasNextPage && !hasPrevPage)) {
        totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
    } else if (hasNextPage) {
        totalPages = Number.POSITIVE_INFINITY;
    } else {
        totalPages = currentPage;
    }
    currentPage = Math.min(Math.max(1, currentPage), totalPages);

    renderNotes();
}

async function refreshProfile() {
    const me = await api("/api/auth/me");
    setProfile(me);
}

async function ensureAuthenticated() {
    await refreshProfile();
}

async function enterNotesAfterAuth(successMessage) {
    navigateTo(PATH_NOTES);
    showAccountView(PATH_NOTES);

    try {
        await refreshProfile();
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

async function renderRoute() {
    const linkConfirmed = await tryConfirmFromLink();
    if (linkConfirmed) {
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
    if (accountPath !== PATH_PROFILE) {
        currentPage = 1;
        notesListEl.innerHTML = "";
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
    await refreshNotes().catch((error) => setStatus(error.message, true));
}

navNotesButtonEl.addEventListener("click", () => openNotesList(PATH_NOTES));
navSharedButtonEl.addEventListener("click", () => openNotesList(PATH_SHARED));

navProfileButtonEl.addEventListener("click", async () => {
    navigateTo(PATH_PROFILE);
    showAccountView(PATH_PROFILE);
    await refreshProfile();
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
