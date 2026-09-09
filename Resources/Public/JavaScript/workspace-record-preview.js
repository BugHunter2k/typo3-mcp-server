/**
 * Record-specific workspace stage/publish buttons for the split preview.
 *
 * Replaces the page-based stage buttons (which would affect ALL records on a
 * storage folder) with buttons that only operate on the single previewed record.
 *
 * Reads record data from TYPO3 inline settings:
 *   TYPO3.settings.WorkspaceRecordPreview = {table, liveUid, versionUid, isNew, currentStage}
 *
 * Uses the workspace_preview AJAX endpoint with sentCollectionToStage —
 * the only publish-capable method allowed in the preview scope.
 */
const STAGE_EDIT = 0;
const STAGE_PUBLISH = -10;
const STAGE_EXECUTE = -20;

class WorkspaceRecordPreview {
    constructor() {
        const settings = TYPO3?.settings?.WorkspaceRecordPreview;
        if (!settings?.table) {
            return;
        }

        this.record = settings;
        this.currentStage = parseInt(this.record.currentStage, 10);
        this.waitForContainer();
    }

    waitForContainer() {
        const container = document.querySelector('.t3js-stage-buttons');
        if (!container) {
            return;
        }

        // preview.js makes an AJAX call that mutates the container.
        // Wait for that mutation, then replace with our buttons.
        let rendered = false;
        const observer = new MutationObserver(() => {
            if (rendered) return;
            rendered = true;
            observer.disconnect();
            setTimeout(() => this.render(container), 100);
        });
        observer.observe(container, { childList: true, subtree: true });

        // Fallback if preview.js finds no page-level changes (no mutation)
        setTimeout(() => {
            if (rendered) return;
            rendered = true;
            observer.disconnect();
            this.render(container);
        }, 3000);
    }

    render(container) {
        let buttons = '';

        if (this.currentStage === STAGE_EDIT) {
            buttons += this.button('t3js-record-ready', 'btn-default', 'actions-arrow-right', 'Ready to Publish');
        } else if (this.currentStage === STAGE_PUBLISH) {
            buttons += this.button('t3js-record-publish', 'btn-success', 'actions-swap', 'Publish');
            buttons += this.button('t3js-record-back', 'btn-default', 'actions-arrow-left', 'Back to Edit');
        }

        buttons += this.button('t3js-record-discard', 'btn-default', 'actions-delete', 'Discard');

        container.innerHTML = '<div class="btn-group">' + buttons + '</div>';

        this.bindAction('t3js-record-ready', () => {
            this.sendStageChange(STAGE_PUBLISH, () => {
                this.currentStage = STAGE_PUBLISH;
                this.render(container);
                this.reloadIframes();
            });
        });

        this.bindAction('t3js-record-publish', () => {
            if (!confirm('Publish this record to live?')) return;
            this.sendStageChange(STAGE_EXECUTE, () => {
                this.reloadIframes();
                container.innerHTML = '<span class="btn btn-sm btn-success disabled">Published</span>';
            });
        });

        this.bindAction('t3js-record-back', () => {
            this.sendStageChange(STAGE_EDIT, () => {
                this.currentStage = STAGE_EDIT;
                this.render(container);
                this.reloadIframes();
            });
        });

        this.bindAction('t3js-record-discard', () => {
            if (!confirm('Discard all changes to this record?')) return;
            this.sendStageChange(STAGE_EDIT, () => {
                this.reloadIframes();
            });
        });
    }

    button(id, btnClass, icon, label) {
        return '<button type="button" class="btn btn-sm ' + btnClass + '" id="' + id + '">'
            + '<typo3-backend-icon identifier="' + icon + '" size="small"></typo3-backend-icon> ' + label
            + '</button>';
    }

    bindAction(id, handler) {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('click', handler);
        }
    }

    sendStageChange(stageId, onSuccess) {
        const record = this.record;
        const affects = {};
        // For new records (t3ver_oid=0), use versionUid as t3ver_oid in the command map.
        // version_swap needs a valid UID to find the record.
        const versionUid = parseInt(record.versionUid, 10);
        const liveUid = parseInt(record.liveUid, 10);
        affects[record.table] = [{
            uid: versionUid,
            t3ver_oid: record.isNew ? versionUid : liveUid,
        }];

        const url = TYPO3.settings.ajaxUrls.workspace_preview;
        const payload = [{
            action: 'Actions',
            method: 'sentCollectionToStage',
            data: [{
                affects: affects,
                stageId: stageId,
                comments: '',
                recipients: [],
            }],
        }];

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(response => response.json())
            .then(data => {
                if (data?.[0]?.result?.success) {
                    onSuccess();
                } else {
                    alert('Action failed. Check the TYPO3 log for details.');
                }
            })
            .catch(error => {
                console.error('Workspace action failed:', error);
                alert('Action failed: ' + error.message);
            });
    }

    reloadIframes() {
        const wsView = document.querySelector('.t3js-workspace-view-workspace');
        const liveView = document.querySelector('.t3js-workspace-view-live');
        if (wsView) wsView.src = wsView.src;
        if (liveView) liveView.src = liveView.src;
    }
}

new WorkspaceRecordPreview();
