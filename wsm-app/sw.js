/* WP Store Manager — Offline-First PWA shell and signed Background Sync.
 * Production Vite builds sync the complete emitted app shell and a content-based
 * cache version into this worker for a reliable offline cold-start.
 * Signed REST requests remain network-only in this worker; selected GETs use the
 * page-level, token-scoped Dexie cache and never include delta-sync or mutations.
 */
var VERSION = 'wsm-offline-shell-20261024-v30';
var CACHE_NAME = 'wsm-offline-shell-v30-20261024';
var IMAGE_CACHE_NAME = 'wsm-offline-images-v1';
var MAX_CROSS_ORIGIN_IMAGE_ENTRIES = 250;
// Vite rewrites this source fallback to include every emitted assets/ file in the built app shell.
var APP_SHELL_PATHS = [
    './index.html',
    './connect.html',
    './desktop.html',
    './club.html',
    './manifest.json',
    './dexie-4.4.6.min.js',
    './icon-192.png',
    './icon-512.png'
];
var DB_NAME = 'WSM_Offline_DB';
var SYNC_TAG = 'wsm-sync-queue';
var LEGACY_SYNC_TAG = 'wsm-sync-outbox';
var PERIODIC_STOCK_SYNC_TAG = 'wsm-periodic-stock-refresh';
var LAST_SYNC_WATERMARK_KEY = 'sync_last_watermark';
var PENDING_DELTA_KEY = 'sync_pending_delta';
var LOG_SETTING_KEY = 'sync_logs_pending';
var AUTH_SETTING_KEY = 'sync_auth';
var PROCESSING_STALE_MS = 5 * 60 * 1000;
var MAX_SERVER_RETRIES = 3;
var MAX_BACKGROUND_ITEMS = 50;
var BACKGROUND_SYNC_TIME_BUDGET_MS = 90000;
var REQUEST_TIMEOUT_MS = 30000;
var AUTH_REFRESH_TIMEOUT_MS = 15000;

function appShellUrls() {
    var scope = self.registration && self.registration.scope ? self.registration.scope : self.location.href;
    return APP_SHELL_PATHS.map(function (path) { return new URL(path, scope).href; });
}

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            // Install atomically: a partial shell must not become the active offline version.
            return cache.addAll(appShellUrls());
        }).then(function () {
            return self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        }).then(function (clients) {
            clients.forEach(function (client) {
                client.postMessage({ type: 'WSM_SW_UPDATE_READY', version: VERSION });
            });
        })
    );
});

self.addEventListener('message', function (event) {
    var data = event && event.data ? event.data : {};
    if (data.type === 'WSM_SKIP_WAITING') {
        event.waitUntil(self.skipWaiting());
    }
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                if (key.indexOf('wsm-') === 0 && key !== CACHE_NAME && key !== IMAGE_CACHE_NAME) {
                    return caches.delete(key);
                }
            }));
        }).then(function () {
            return self.clients.claim();
        }).then(function () {
            return self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        }).then(function (clients) {
            clients.forEach(function (client) {
                client.postMessage({ type: 'WSM_SW_ACTIVE', version: VERSION });
            });
        })
    );
});

function openLocalDatabase() {
    return new Promise(function (resolve, reject) {
        if (!self.indexedDB) {
            reject(new Error('IndexedDB is not available in the Service Worker.'));
            return;
        }
        var request;
        try {
            // Open the existing database without specifying a version. The Worker
            // never creates stores, indexes, or a database-version migration.
            request = self.indexedDB.open(DB_NAME);
        } catch (error) {
            reject(error);
            return;
        }
        request.onupgradeneeded = function (event) {
            var database = event.target.result;
            if (!database.objectStoreNames.contains('settings') || !database.objectStoreNames.contains('sync_queue')) {
                try { event.target.transaction.abort(); } catch (abortError) {}
                return;
            }
            try {
                if (!event.target.transaction.objectStore('sync_queue').indexNames.contains('status')) event.target.transaction.abort();
            } catch (error) { try { event.target.transaction.abort(); } catch (abortError) {} }
        };
        request.onerror = function () {
            reject(request.error || new Error('Unable to open the existing WSM local database.'));
        };
        request.onsuccess = function () {
            var database = request.result;
            if (!database.objectStoreNames.contains('settings') || !database.objectStoreNames.contains('sync_queue')) {
                database.close();
                reject(new Error('The existing WSM database is missing required stores.'));
                return;
            }
            try {
                if (!database.transaction('sync_queue', 'readonly').objectStore('sync_queue').indexNames.contains('status')) {
                    database.close();
                    reject(new Error('The existing WSM database is missing the sync_queue status index.'));
                    return;
                }
            } catch (error) {
                database.close();
                reject(error || new Error('The existing WSM database cannot read the sync_queue status index.'));
                return;
            }
            database.onversionchange = function () { database.close(); };
            resolve(database);
        };
    });
}

function readRecord(database, storeName, key) {
    return new Promise(function (resolve, reject) {
        var transaction;
        var request;
        try {
            transaction = database.transaction(storeName, 'readonly');
            request = transaction.objectStore(storeName).get(key);
        } catch (error) {
            reject(error);
            return;
        }
        request.onsuccess = function () { resolve(typeof request.result === 'undefined' ? null : request.result); };
        request.onerror = function () { reject(request.error || new Error('IndexedDB read failed.')); };
        transaction.onabort = function () { reject(transaction.error || new Error('IndexedDB read was aborted.')); };
    });
}

function readAllRecords(database, storeName) {
    return new Promise(function (resolve, reject) {
        var transaction;
        var request;
        try {
            transaction = database.transaction(storeName, 'readonly');
            request = transaction.objectStore(storeName).getAll();
        } catch (error) {
            reject(error);
            return;
        }
        request.onsuccess = function () { resolve(Array.isArray(request.result) ? request.result : []); };
        request.onerror = function () { reject(request.error || new Error('IndexedDB list read failed.')); };
        transaction.onabort = function () { reject(transaction.error || new Error('IndexedDB list read was aborted.')); };
    });
}
function readRecordsByIndexValues(database, storeName, indexName, keys) {
    return new Promise(function (resolve, reject) {
        var transaction;
        var records = [];
        var failed = false;
        function fail(error) {
            if (failed) return;
            failed = true;
            reject(error || new Error('IndexedDB indexed cursor read failed.'));
        }
        try {
            transaction = database.transaction(storeName, 'readonly');
            var index = transaction.objectStore(storeName).index(indexName);
            (Array.isArray(keys) ? keys : []).forEach(function (key) {
                var request = index.openCursor(key);
                request.onsuccess = function () {
                    var cursor = request.result;
                    if (!cursor) return;
                    records.push(cursor.value);
                    cursor.continue();
                };
                request.onerror = function () { fail(request.error); };
            });
        } catch (error) {
            fail(error);
            return;
        }
        transaction.oncomplete = function () { if (!failed) resolve(records); };
        transaction.onerror = function () { fail(transaction.error || new Error('IndexedDB indexed cursor transaction failed.')); };
        transaction.onabort = function () { fail(transaction.error || new Error('IndexedDB indexed cursor transaction was aborted.')); };
    });
}

function readRecordsByIndex(database, storeName, indexName, key) {
    return readRecordsByIndexValues(database, storeName, indexName, [key]);
}

function saveSetting(database, record) {
    return new Promise(function (resolve, reject) {
        var transaction;
        try {
            transaction = database.transaction('settings', 'readwrite');
            transaction.objectStore('settings').put(record);
        } catch (error) {
            reject(error);
            return;
        }
        transaction.oncomplete = function () { resolve(true); };
        transaction.onerror = function () { reject(transaction.error || new Error('IndexedDB setting write failed.')); };
        transaction.onabort = function () { reject(transaction.error || new Error('IndexedDB setting write was aborted.')); };
    });
}

function backgroundQueueActionMatchesMember(record, memberUUID) {
    var action = String(record && record.action_type || '').toUpperCase();
    if (['CREATE_MEMBER', 'CREATE_MEMBERSHIP', 'CREATE_SALE'].indexOf(action) < 0) return false;
    if (['pending', 'processing', 'failed'].indexOf(String(record && record.status || '').toLowerCase()) < 0) return false;
    var payload = parseQueuePayload(record) || {};
    return String(payload.member_uuid || payload.uuid || '') === String(memberUUID || '');
}

function backgroundPendingPosSaleDebt(queueRows, memberUUID) {
    return (Array.isArray(queueRows) ? queueRows : []).reduce(function (sum, record) {
        if (!backgroundQueueActionMatchesMember(record, memberUUID) || String(record.action_type || '').toUpperCase() !== 'CREATE_SALE') return sum;
        var payload = parseQueuePayload(record) || {};
        var method = String(payload.method || payload.payment_method || '').toLowerCase();
        if (method !== 'member_debt' && !payload.is_member_debt) return sum;
        var amount = Number(payload.total || payload.amount || 0);
        return sum + (Number.isFinite(amount) && amount > 0 ? amount : 0);
    }, 0);
}

function backgroundPendingMembershipDebt(member) {
    return (Array.isArray(member && member.pending_memberships) ? member.pending_memberships : []).reduce(function (sum, item) {
        if (String(item && item.sync_status || 'pending') === 'cancelled') return sum;
        var amount = Number(item && item.balance_due || 0);
        return sum + (Number.isFinite(amount) && amount > 0 ? amount : 0);
    }, 0);
}

function applyBackgroundServerDebtSnapshot(member, syncResult, queueRows, memberUUID) {
    var membershipDebt = Number(syncResult && syncResult.member_balance_due);
    if (Number.isFinite(membershipDebt)) member.balance_due = Math.max(0, membershipDebt) + backgroundPendingMembershipDebt(member);
    var posDebt = Number(syncResult && syncResult.member_pos_debt_due);
    if (Number.isFinite(posDebt)) member.pos_debt_due = Math.max(0, posDebt) + backgroundPendingPosSaleDebt(queueRows, memberUUID);
    else if (!Number.isFinite(Number(member.pos_debt_due))) member.pos_debt_due = backgroundPendingPosSaleDebt(queueRows, memberUUID);
    member.total_debt_due = Math.max(0, Number(member.balance_due || 0)) + Math.max(0, Number(member.pos_debt_due || 0));
}

function applyBackgroundMemberSyncState(member, queueRows, memberUUID) {
    var memberQueue = (Array.isArray(queueRows) ? queueRows : []).filter(function (record) {
        return backgroundQueueActionMatchesMember(record, memberUUID);
    });
    var hasFailed = memberQueue.some(function (record) { return String(record.status || '').toLowerCase() === 'failed'; });
    member.sync_status = hasFailed ? 'failed' : (member.local_only || memberQueue.length > 0 ? 'pending' : 'synced');
    if (member.sync_status === 'synced') {
        member.synced_at = Date.now();
        delete member.failed_at;
    } else if (hasFailed) {
        member.failed_at = Date.now();
    }
}

function withBackgroundMemberSnapshot(queueStore, memberStore, memberUUID, memberId, callback) {
    var queueRowsRequest = queueStore.getAll();
    queueRowsRequest.onsuccess = function () {
        var queueRows = Array.isArray(queueRowsRequest.result) ? queueRowsRequest.result : [];
        if (memberUUID) {
            var memberRequest = memberStore.get(memberUUID);
            memberRequest.onsuccess = function () { callback(memberRequest.result || null, queueRows); };
            return;
        }
        if (Number(memberId) > 0) {
            var memberRowsRequest = memberStore.getAll();
            memberRowsRequest.onsuccess = function () {
                var memberRows = Array.isArray(memberRowsRequest.result) ? memberRowsRequest.result : [];
                var target = memberRows.find(function (member) {
                    return Number(member.remote_id || member.member_id || member.id || 0) === Number(memberId);
                }) || null;
                callback(target, queueRows);
            };
        }
    };
}

function writeQueueItem(database, queueId, expectedOwner, updates, deleteRecord) {
    return new Promise(function (resolve, reject) {
        var transaction;
        var updated = false;
        try {
            transaction = database.transaction(['sync_queue', 'members'], 'readwrite');
            var store = transaction.objectStore('sync_queue');
            var memberStore = transaction.objectStore('members');
            var request = store.get(queueId);
            request.onsuccess = function () {
                var record = request.result;
                if (!record || String(record.status || '').toLowerCase() !== 'processing' || record.processing_owner !== expectedOwner) return;
                if (deleteRecord === true) {
                    store.delete(queueId);
                    var action = String(record.action_type || '').toUpperCase();
                    var payload = parseQueuePayload(record) || {};
                    var syncResult = updates && updates.sync_result && typeof updates.sync_result === 'object' ? updates.sync_result : {};
                    var now = Date.now();
                    if (action === 'CREATE_MEMBER') {
                        var memberUUID = String(payload.member_uuid || payload.uuid || '');
                        var serverId = Number(syncResult.id || syncResult.member_id || 0);
                        if (memberUUID) {
                            withBackgroundMemberSnapshot(store, memberStore, memberUUID, serverId, function (member, queueRows) {
                                if (!member) return;
                                var hasServerId = Number.isSafeInteger(serverId) && serverId > 0;
                                var nextMember = Object.assign({}, member, {
                                    local_only: hasServerId ? false : !!member.local_only,
                                    sync_linked: hasServerId ? true : !!member.sync_linked,
                                    updated_at: now
                                });
                                if (hasServerId) {
                                    nextMember.id = serverId; nextMember.remote_id = serverId; nextMember.member_id = serverId;
                                    delete nextMember.failed_at;
                                }
                                if (syncResult.member_code) nextMember.member_code = String(syncResult.member_code);
                                applyBackgroundMemberSyncState(nextMember, queueRows, memberUUID);
                                memberStore.put(nextMember);
                            });
                        }
                    } else if (action === 'CREATE_MEMBERSHIP') {
                        var targetUUID = String(payload.member_uuid || '');
                        var targetId = Number(syncResult.member_id || payload.member_id || 0);
                        var membershipUUID = String(payload.membership_uuid || syncResult.membership_uuid || '');
                        withBackgroundMemberSnapshot(store, memberStore, targetUUID, targetId, function (member, queueRows) {
                            if (!member) return;
                            member.pending_memberships = (Array.isArray(member.pending_memberships) ? member.pending_memberships : []).filter(function (item) {
                                return String(item && item.membership_uuid || '') !== membershipUUID;
                            });
                            if (Number.isSafeInteger(targetId) && targetId > 0) {
                                member.id = targetId; member.remote_id = targetId; member.member_id = targetId;
                                member.local_only = false; member.sync_linked = true;
                            }
                            applyBackgroundServerDebtSnapshot(member, syncResult, queueRows, targetUUID || String(member.member_uuid || member.uuid || ''));
                            if (syncResult.membership && typeof syncResult.membership === 'object' && String(syncResult.membership.status || '') === 'active') member.membership = syncResult.membership;
                            member.updated_at = now;
                            applyBackgroundMemberSyncState(member, queueRows, targetUUID || String(member.member_uuid || member.uuid || ''));
                            memberStore.put(member);
                        });
                    } else if (action === 'CREATE_SALE') {
                        var saleMemberUUID = String(payload.member_uuid || '');
                        var saleMemberId = Number(syncResult.member_id || payload.member_id || 0);
                        if (saleMemberUUID || saleMemberId > 0) {
                            withBackgroundMemberSnapshot(store, memberStore, saleMemberUUID, saleMemberId, function (member, queueRows) {
                                if (!member) return;
                                if (Number.isSafeInteger(saleMemberId) && saleMemberId > 0) {
                                    member.id = saleMemberId; member.remote_id = saleMemberId; member.member_id = saleMemberId;
                                    member.local_only = false; member.sync_linked = true;
                                }
                                var stateUUID = saleMemberUUID || String(member.member_uuid || member.uuid || '');
                                applyBackgroundServerDebtSnapshot(member, syncResult, queueRows, stateUUID);
                                member.updated_at = now;
                                applyBackgroundMemberSyncState(member, queueRows, stateUUID);
                                memberStore.put(member);
                            });
                        }
                    }
                    updated = true;
                    return;
                }
                var next = Object.assign({}, record, updates || {}, { id: queueId, updated_at: Date.now() });
                if (next.status !== 'pending' && next.status !== 'failed') next.status = 'pending';
                delete next.processing_at;
                delete next.processing_started_at;
                delete next.processing_owner;
                store.put(next);
                if (String(record.action_type || '').toUpperCase() === 'CREATE_MEMBERSHIP') {
                    var payloadForPreview = parseQueuePayload(record) || {};
                    var membershipUUIDForPreview = String(payloadForPreview.membership_uuid || '');
                    var memberUUIDForPreview = String(payloadForPreview.member_uuid || '');
                    var targetMemberIdForPreview = Number(payloadForPreview.member_id || 0);
                    function updateMembershipPreview(member) {
                        if (!member || !membershipUUIDForPreview) return;
                        var previews = Array.isArray(member.pending_memberships) ? member.pending_memberships.slice() : [];
                        var previewIndex = previews.findIndex(function (item) { return String(item && item.membership_uuid || '') === membershipUUIDForPreview; });
                        if (previewIndex < 0) return;
                        previews[previewIndex] = Object.assign({}, previews[previewIndex], {
                            sync_status: String(next.status || 'pending'),
                            sync_error: String(next.error_message || next.last_error || '').slice(0, 1000),
                            updated_at: Date.now()
                        });
                        member.pending_memberships = previews;
                        member.updated_at = Date.now();
                        memberStore.put(member);
                    }
                    if (memberUUIDForPreview) {
                        var previewMemberRequest = memberStore.get(memberUUIDForPreview);
                        previewMemberRequest.onsuccess = function () { updateMembershipPreview(previewMemberRequest.result); };
                    } else if (targetMemberIdForPreview > 0) {
                        var previewMemberRowsRequest = memberStore.getAll();
                        previewMemberRowsRequest.onsuccess = function () {
                            var rows = Array.isArray(previewMemberRowsRequest.result) ? previewMemberRowsRequest.result : [];
                            updateMembershipPreview(rows.find(function (member) { return Number(member.remote_id || member.member_id || member.id || 0) === targetMemberIdForPreview; }) || null);
                        };
                    }
                }
                updated = true;
            };
        } catch (error) {
            reject(error);
            return;
        }
        transaction.oncomplete = function () { resolve(updated); };
        transaction.onerror = function () { reject(transaction.error || new Error('IndexedDB queue update failed.')); };
        transaction.onabort = function () { reject(transaction.error || new Error('IndexedDB queue update was aborted.')); };
    });
}

function updateProcessingQueueItem(database, queueId, owner, updates) {
    return new Promise(function (resolve, reject) {
        var transaction;
        var updatedRecord = null;
        try {
            transaction = database.transaction('sync_queue', 'readwrite');
            var store = transaction.objectStore('sync_queue');
            var request = store.get(queueId);
            request.onsuccess = function () {
                var record = request.result;
                if (!record || String(record.status || '').toLowerCase() !== 'processing' || record.processing_owner !== owner) return;
                updatedRecord = Object.assign({}, record, updates || {}, { id: queueId, status: 'processing', processing_owner: owner });
                store.put(updatedRecord);
            };
        } catch (error) {
            reject(error);
            return;
        }
        transaction.oncomplete = function () { resolve(updatedRecord); };
        transaction.onerror = function () { reject(transaction.error || new Error('IndexedDB queue update failed.')); };
        transaction.onabort = function () { reject(transaction.error || new Error('IndexedDB queue update was aborted.')); };
    });
}

function claimQueueItem(database, queueId, owner) {
    return new Promise(function (resolve, reject) {
        var transaction;
        var claimed = null;
        try {
            transaction = database.transaction('sync_queue', 'readwrite');
            var store = transaction.objectStore('sync_queue');
            var request = store.get(queueId);
            request.onsuccess = function () {
                var record = request.result;
                if (!record || String(record.status || '').toLowerCase() !== 'pending') return;
                claimed = Object.assign({}, record, {
                    status: 'processing',
                    processing_at: Date.now(),
                    processing_owner: owner,
                    updated_at: Date.now()
                });
                if (String(record.action_type || '').toUpperCase() === 'CREATE_MEMBERSHIP') {
                    var membershipPayload = parseQueuePayload(record) || {};
                    membershipPayload.server_membership_may_exist = true;
                    claimed.payload = JSON.stringify(membershipPayload);
                }
                store.put(claimed);
            };
        } catch (error) {
            reject(error);
            return;
        }
        transaction.oncomplete = function () { resolve(claimed); };
        transaction.onerror = function () { reject(transaction.error || new Error('IndexedDB queue claim failed.')); };
        transaction.onabort = function () { reject(transaction.error || new Error('IndexedDB queue claim was aborted.')); };
    });
}

function numericTime(value) {
    var numeric = Number(value);
    if (Number.isFinite(numeric) && numeric > 0) return numeric;
    var parsed = Date.parse(String(value || ''));
    return Number.isFinite(parsed) ? parsed : 0;
}

async function recoverStaleProcessing(database) {
    var records = await readRecordsByIndex(database, 'sync_queue', 'status', 'processing');
    var recovered = 0;
    var now = Date.now();
    for (var index = 0; index < records.length; index++) {
        var record = records[index];
        if (!record || String(record.status || '').toLowerCase() !== 'processing') continue;
        var started = numericTime(record.processing_at || record.processing_started_at || record.updated_at || record.created_at);
        if (now - started < PROCESSING_STALE_MS) continue;
        var changed = await new Promise(function (resolve, reject) {
            var transaction;
            var didChange = false;
            try {
                transaction = database.transaction('sync_queue', 'readwrite');
                var store = transaction.objectStore('sync_queue');
                var request = store.get(String(record.id || ''));
                request.onsuccess = function () {
                    var current = request.result;
                    if (!current || String(current.status || '').toLowerCase() !== 'processing' || current.processing_owner !== record.processing_owner) return;
                    var currentStarted = numericTime(current.processing_at || current.processing_started_at || current.updated_at || current.created_at);
                    if (Date.now() - currentStarted < PROCESSING_STALE_MS) return;
                    var next = Object.assign({}, current, { status: 'pending', updated_at: Date.now(), recovered_at: Date.now() });
                    delete next.processing_at;
                    delete next.processing_started_at;
                    delete next.processing_owner;
                    store.put(next);
                    didChange = true;
                };
            } catch (error) {
                reject(error);
                return;
            }
            transaction.oncomplete = function () { resolve(didChange); };
            transaction.onerror = function () { reject(transaction.error || new Error('IndexedDB stale-row recovery failed.')); };
            transaction.onabort = function () { reject(transaction.error || new Error('IndexedDB stale-row recovery was aborted.')); };
        });
        if (changed) recovered++;
    }
    return recovered;
}

function appendWorkerLog(database, details) {
    details = details && typeof details === 'object' ? details : {};
    return new Promise(function (resolve, reject) {
        var transaction;
        try {
            transaction = database.transaction('settings', 'readwrite');
            var store = transaction.objectStore('settings');
            var request = store.get(LOG_SETTING_KEY);
            request.onsuccess = function () {
                var current = request.result;
                var events = current && Array.isArray(current.events) ? current.events.slice() : [];
                var entry = {
                    at: Date.now(),
                    type: String(details.type || 'background_sync').slice(0, 60),
                    message: String(details.message || '').slice(0, 500)
                };
                if (details.queue_id) entry.queue_id = String(details.queue_id).slice(0, 80);
                if (Number(details.http_status) > 0) entry.http_status = Number(details.http_status);
                if (Number.isFinite(Number(details.count))) entry.count = Number(details.count);
                if (Array.isArray(details.product_ids)) entry.product_ids = details.product_ids.map(Number).filter(Number.isFinite).slice(0, 50);
                if (details.stock_conflict) entry.stock_conflict = true;
                events.push(entry);
                store.put({ key: LOG_SETTING_KEY, events: events.slice(-50), updated_at: Date.now() });
            };
        } catch (error) {
            reject(error);
            return;
        }
        transaction.oncomplete = function () { resolve(true); };
        transaction.onerror = function () { reject(transaction.error || new Error('Background sync log write failed.')); };
        transaction.onabort = function () { reject(transaction.error || new Error('Background sync log write was aborted.')); };
    });
}

function safeLog(database, details) {
    return appendWorkerLog(database, details).catch(function () { return false; });
}

function createNonce() {
    var bytes = new Uint8Array(24);
    self.crypto.getRandomValues(bytes);
    var result = '';
    for (var index = 0; index < bytes.length; index++) result += bytes[index].toString(16).padStart(2, '0');
    return result;
}

function bytesToHex(bytes) {
    var result = '';
    for (var index = 0; index < bytes.length; index++) result += bytes[index].toString(16).padStart(2, '0');
    return result;
}

async function sha256Hex(text) {
    var bytes = new TextEncoder().encode(text);
    var digest = await self.crypto.subtle.digest('SHA-256', bytes);
    return bytesToHex(new Uint8Array(digest));
}

async function hmacSha256Hex(secret, message) {
    var encoder = new TextEncoder();
    var key = await self.crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    var signature = await self.crypto.subtle.sign('HMAC', key, encoder.encode(message));
    return bytesToHex(new Uint8Array(signature));
}

function endpointFor(auth, route) {
    var apiBase = String(auth.api_base || '');
    if (!apiBase) throw new Error('Background Sync API base is missing.');
    if (apiBase.charAt(apiBase.length - 1) !== '/') apiBase += '/';
    var endpoint = new URL(apiBase + route, self.location.href);
    if (endpoint.origin !== self.location.origin) throw new Error('Background Sync refuses to send device credentials cross-origin.');
    return endpoint.href;
}

async function signedSyncHeaders(auth, body) {
    if (!self.crypto || !self.crypto.subtle) throw new Error('Web Crypto is unavailable for signed Background Sync.');
    var timestamp = String(Math.floor(Date.now() / 1000));
    var nonce = createNonce();
    var bodyHash = await sha256Hex(body);
    var canonical = 'POST\n/wsm/v1/sync/bulk\n' + timestamp + '\n' + nonce + '\n' + bodyHash;
    var signature = await hmacSha256Hex(String(auth.signing_key || ''), canonical);
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + String(auth.access_token || ''),
        'X-WSM-Token': String(auth.access_token || ''),
        'X-WSM-Timestamp': timestamp,
        'X-WSM-Nonce': nonce,
        'X-WSM-Signature': signature
    };
}

async function signedSyncGetHeaders(auth, route) {
    if (!self.crypto || !self.crypto.subtle) throw new Error('Web Crypto is unavailable for signed periodic sync.');
    var timestamp = String(Math.floor(Date.now() / 1000));
    var nonce = createNonce();
    var bodyHash = await sha256Hex('');
    var canonicalRoute = '/wsm/v1/' + String(route || '').replace(/^\/+/, '').split(/[?#]/)[0];
    var canonical = 'GET\n' + canonicalRoute + '\n' + timestamp + '\n' + nonce + '\n' + bodyHash;
    var signature = await hmacSha256Hex(String(auth.signing_key || ''), canonical);
    return {
        Accept: 'application/json',
        Authorization: 'Bearer ' + String(auth.access_token || ''),
        'X-WSM-Token': String(auth.access_token || ''),
        'X-WSM-Timestamp': timestamp,
        'X-WSM-Nonce': nonce,
        'X-WSM-Signature': signature
    };
}

async function refreshBackgroundAuth(database, auth) {
    if (!auth.refresh_token) return false;
    var response, text;
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timeout = controller ? setTimeout(function () { controller.abort(); }, AUTH_REFRESH_TIMEOUT_MS) : 0;
    try {
        response = await fetch(endpointFor(auth, 'refresh'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ refresh_token: String(auth.refresh_token) }),
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller ? controller.signal : undefined
        });
        text = await response.text();
    } catch (error) {
        return false;
    } finally {
        if (timeout) clearTimeout(timeout);
    }
    var data = null;
    try { data = text ? JSON.parse(text) : null; } catch (error) {}
    if (!response.ok || !data || !(data.token || data.access_token)) return false;
    auth.access_token = String(data.token || data.access_token);
    if (data.refresh_token) auth.refresh_token = String(data.refresh_token);
    if (data.device_id) auth.device_id = String(data.device_id);
    auth.disabled = false;
    auth.updated_at = Date.now();
    await saveSetting(database, Object.assign({}, auth, { key: AUTH_SETTING_KEY }));
    await notifyClients({
        type: 'WSM_SW_AUTH_UPDATED',
        access_token: auth.access_token,
        refresh_token: auth.refresh_token || '',
        device_id: auth.device_id || '',
        signing_key: auth.signing_key || ''
    });
    return true;
}

function parseQueuePayload(record) {
    if (record && record.payload && typeof record.payload === 'object' && !Array.isArray(record.payload)) return record.payload;
    if (record && typeof record.payload === 'string') {
        try {
            var payload = JSON.parse(record.payload);
            return payload && typeof payload === 'object' && !Array.isArray(payload) ? payload : null;
        } catch (error) {}
    }
    return null;
}

function queueMemberUUID(record) {
    var payload = parseQueuePayload(record) || {};
    return String(payload.member_uuid || payload.uuid || '');
}

function isIndexedCreatedAt(value) {
    if (typeof value === 'number') return Number.isFinite(value);
    if (typeof value === 'string') return value.length > 0;
    return value instanceof Date && Number.isFinite(value.getTime());
}

// One readonly transaction supplies a bounded, oldest-first pending page plus
// the minimal active CREATE_MEMBER dependency map. This avoids retaining every
// sale payload in memory and prevents a dependent sale overtaking its member.
function readBackgroundQueuePage(database, limit) {
    return new Promise(function (resolve, reject) {
        var transaction, store, statusIndex, createdAtIndex;
        var records = [], dependencies = new Map(), selectedParentIds = new Set(), seenIds = new Set();
        var cap = Number(limit);
        cap = Number.isFinite(cap) && cap >= 0 ? Math.floor(cap) : MAX_BACKGROUND_ITEMS;
        if (cap === 0) { resolve({ records: records, dependencies: dependencies }); return; }
        var failed = false, remainingStatusCursors = 0;
        function fail(error) {
            if (failed) return;
            failed = true;
            reject(error || new Error('IndexedDB background queue page failed.'));
        }
        function memberDependencyState(uuid) {
            var state = dependencies.get(uuid);
            if (!state) {
                state = { activeIds: new Set(), pendingIds: new Set(), nonPendingIds: new Set() };
                dependencies.set(uuid, state);
            }
            return state;
        }
        function recordId(record) {
            return String(record && (record.id || record.queue_id) || '').trim();
        }
        function dependentIsEligible(record) {
            var action = String(record && record.action_type || '').toUpperCase();
            if (action !== 'CREATE_MEMBERSHIP' && action !== 'CREATE_SALE') return true;
            var payload = parseQueuePayload(record) || {};
            var uuid = String(payload.member_uuid || '');
            if (!uuid) return Number(payload.member_id || 0) > 0;
            var state = dependencies.get(uuid);
            if (!state || !state.activeIds.size) return true;
            if (state.nonPendingIds.size) return false;
            var pendingIds = Array.from(state.pendingIds);
            return pendingIds.length > 0 && pendingIds.every(function (id) { return selectedParentIds.has(id); });
        }
        function consider(record, allowMissingCreatedAt) {
            if (!record || String(record.status || '').toLowerCase() !== 'pending') return false;
            if (allowMissingCreatedAt && isIndexedCreatedAt(record.created_at)) return false;
            var id = recordId(record);
            if (!id || seenIds.has(id) || !dependentIsEligible(record)) return false;
            seenIds.add(id);
            records.push(record);
            if (String(record.action_type || '').toUpperCase() === 'CREATE_MEMBER') selectedParentIds.add(id);
            return true;
        }
        function beginMissingMemberFallback() {
            if (records.length >= cap) return;
            var request;
            try { request = statusIndex.openCursor('pending'); }
            catch (error) { fail(error); return; }
            request.onsuccess = function () {
                var cursor = request.result;
                if (!cursor) { beginMissingOtherFallback(); return; }
                var record = cursor.value;
                if (String(record && record.action_type || '').toUpperCase() === 'CREATE_MEMBER') consider(record, true);
                if (records.length < cap) cursor.continue();
            };
            request.onerror = function () { fail(request.error); };
        }
        function beginMissingOtherFallback() {
            if (records.length >= cap) return;
            var request;
            try { request = statusIndex.openCursor('pending'); }
            catch (error) { fail(error); return; }
            request.onsuccess = function () {
                var cursor = request.result;
                if (!cursor) return;
                var record = cursor.value;
                if (String(record && record.action_type || '').toUpperCase() !== 'CREATE_MEMBER') consider(record, true);
                if (records.length < cap) cursor.continue();
            };
            request.onerror = function () { fail(request.error); };
        }
        function beginCreatedAtCursor() {
            var request;
            try { request = createdAtIndex.openCursor(); }
            catch (error) { fail(error); return; }
            request.onsuccess = function () {
                var cursor = request.result;
                if (!cursor) { beginMissingMemberFallback(); return; }
                consider(cursor.value, false);
                if (records.length < cap) cursor.continue();
            };
            request.onerror = function () { fail(request.error); };
        }
        try {
            transaction = database.transaction('sync_queue', 'readonly');
            store = transaction.objectStore('sync_queue');
            statusIndex = store.index('status');
            createdAtIndex = store.index('created_at');
            var statuses = ['pending', 'processing', 'failed'];
            remainingStatusCursors = statuses.length;
            statuses.forEach(function (status) {
                var request = statusIndex.openCursor(status);
                request.onsuccess = function () {
                    var cursor = request.result;
                    if (cursor) {
                        var record = cursor.value;
                        if (String(record && record.action_type || '').toUpperCase() === 'CREATE_MEMBER') {
                            var uuid = queueMemberUUID(record), id = recordId(record);
                            if (uuid && id) {
                                var state = memberDependencyState(uuid);
                                state.activeIds.add(id);
                                if (status === 'pending') state.pendingIds.add(id);
                                else state.nonPendingIds.add(id);
                            }
                        }
                        cursor.continue();
                        return;
                    }
                    remainingStatusCursors--;
                    if (remainingStatusCursors === 0 && !failed) beginCreatedAtCursor();
                };
                request.onerror = function () { fail(request.error); };
            });
        } catch (error) {
            fail(error);
            return;
        }
        transaction.oncomplete = function () {
            if (!failed) resolve({ records: records, dependencies: dependencies });
        };
        transaction.onerror = function () { fail(transaction.error || new Error('IndexedDB background queue page transaction failed.')); };
        transaction.onabort = function () { fail(transaction.error || new Error('IndexedDB background queue page transaction was aborted.')); };
    });
}

function countRecordsByIndex(database, storeName, indexName, key) {
    return new Promise(function (resolve, reject) {
        var transaction, request, count = 0;
        try {
            transaction = database.transaction(storeName, 'readonly');
            request = transaction.objectStore(storeName).index(indexName).count(key);
            request.onsuccess = function () { count = Number(request.result) || 0; };
            request.onerror = function () { reject(request.error || new Error('IndexedDB indexed count failed.')); };
        } catch (error) {
            reject(error);
            return;
        }
        transaction.oncomplete = function () { resolve(count); };
        transaction.onerror = function () { reject(transaction.error || new Error('IndexedDB indexed count transaction failed.')); };
        transaction.onabort = function () { reject(transaction.error || new Error('IndexedDB indexed count transaction was aborted.')); };
    });
}

function productIdsForRecord(record) {
    var payload = parseQueuePayload(record);
    var ids = [];
    function add(value) {
        var number = Number(value);
        if (Number.isFinite(number) && number > 0 && ids.indexOf(number) === -1) ids.push(number);
    }
    if (!payload) return ids;
    if (Array.isArray(payload.items)) {
        payload.items.forEach(function (item) {
            if (item && typeof item === 'object') add(item.product_id || item.productId || item.remote_id || item.id);
        });
    }
    add(payload.product_id || payload.productId || payload.remote_id);
    return ids.slice(0, 50);
}

function isInventoryConflict(code, message) {
    var value = String(code || '').toLowerCase() + ' ' + String(message || '').toLowerCase();
    return String(code || '').toLowerCase() === 'wsm_pos_stock_shortage' || /(stock|out.?of.?stock|inventory|insufficient_stock|موجودی.{0,30}(کافی|کم)|کمبود موجودی)/i.test(value);
}

function classifyBackgroundResponse(data, record) {
    if (!data || typeof data !== 'object' || Array.isArray(data)) {
        return { kind: 'failed', error: 'پاسخ JSON سرور برای همگام‌سازی معتبر نیست.', code: 'wsm_sync_invalid_response', status: 200, serverError: true, retryable: true };
    }
    var queueId = String(record && (record.id || record.queue_id) || ''), outcomes = data.outcomes && typeof data.outcomes === 'object' && !Array.isArray(data.outcomes) ? data.outcomes : null;
    var item = outcomes && outcomes[queueId] && typeof outcomes[queueId] === 'object' ? outcomes[queueId] : null;
    if (item) {
        var itemStatus = String(item.status || '').toLowerCase();
        if (itemStatus === 'processed') return { kind: 'processed', data: item.data && typeof item.data === 'object' ? item.data : null };
        if (itemStatus === 'duplicate') return { kind: 'duplicate', data: item.data && typeof item.data === 'object' ? item.data : null };
        if (itemStatus === 'failed') return { kind: 'failed', error: String(item.message || data.message || 'سرور این آیتم همگام‌سازی را نپذیرفت.'), code: String(item.code || ''), status: Number(item.http_status || 200) || 200, serverError: false };
    }
    var code = String(data.code || (data.error && data.error.code) || '');
    var message = String(data.message || 'سرور این آیتم همگام‌سازی را نپذیرفت.');
    if (data.success !== true) {
        return { kind: 'failed', error: message, code: code, status: 200, stockConflict: isInventoryConflict(code, message) };
    }
    var processed = Math.max(0, Number(data.processed) || 0);
    var failed = Math.max(0, Number(data.failed) || 0);
    var duplicates = Math.max(0, Number(data.duplicates_ignored) || 0);
    if (failed > 0) {
        var saleRejected = String(record.action_type || '').toUpperCase() === 'CREATE_SALE';
        return {
            kind: 'failed',
            error: message || 'پردازش آیتم در سرور ناموفق بود.',
            code: code,
            status: 200,
            stockConflict: isInventoryConflict(code, message) || saleRejected,
            probableStockConflict: saleRejected
        };
    }
    if (processed > 0) return { kind: 'processed', data: null };
    if (duplicates > 0) return { kind: 'duplicate', data: null };
    return { kind: 'pending', error: 'سرور نتیجهٔ این آیتم را تأیید نکرد.' };
}

async function postSyncItem(database, auth, record) {
    var queueId = String(record.id || record.queue_id || '').trim();
    var actionType = String(record.action_type || '').trim().toUpperCase();
    if (!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(queueId)) {
        return { kind: 'failed', error: 'شناسهٔ queue_id معتبر نیست.', code: 'wsm_sync_queue_id_invalid', status: 400 };
    }
    if (actionType !== 'CREATE_SALE' && actionType !== 'CREATE_MEMBER' && actionType !== 'CREATE_MEMBERSHIP') {
        return { kind: 'failed', error: 'نوع عملیات صف پشتیبانی نمی‌شود.', code: 'wsm_sync_action_invalid', status: 400 };
    }
    var payload = typeof record.payload === 'string' ? record.payload : JSON.stringify(record.payload);
    try {
        var parsed = JSON.parse(payload);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) throw new Error('Invalid payload object.');
    } catch (error) {
        return { kind: 'failed', error: 'JSON payload صف همگام‌سازی معتبر نیست.', code: 'wsm_sync_payload_invalid', status: 400 };
    }
    var serverItem = { queue_id: queueId, action_type: actionType, payload: payload };
    var body = JSON.stringify({ items: [serverItem] });
    var refreshed = false;
    while (true) {
        var headers = await signedSyncHeaders(auth, body);
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timeout = 0;
        if (controller) timeout = setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS);
        var response, responseText;
        try {
            response = await fetch(endpointFor(auth, 'sync/bulk'), {
                method: 'POST',
                headers: headers,
                body: body,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller ? controller.signal : undefined
            });
            // Keep the abort timer active until the entire response body is read.
            responseText = await response.text();
        } catch (error) {
            error.networkError = true;
            throw error;
        } finally {
            if (timeout) clearTimeout(timeout);
        }
        var data = null;
        try { data = responseText ? JSON.parse(responseText) : null; } catch (error) {}
        if (response.status === 401 && !refreshed && await refreshBackgroundAuth(database, auth)) {
            refreshed = true;
            continue;
        }
        if (!response.ok) {
            var serverError = new Error(String(data && data.message || (response.status >= 500 ? 'خطای موقت سرور.' : 'درخواست همگام‌سازی رد شد.')));
            serverError.status = response.status;
            serverError.code = String(data && (data.code || (data.error && data.error.code)) || '');
            serverError.serverError = true;
            serverError.responseData = data;
            if (response.status === 401 || response.status === 403) serverError.blocked = true;
            else if (response.status >= 500 || response.status === 408 || response.status === 429 || response.status === 409) serverError.retryable = true;
            else serverError.retryable = false;
            throw serverError;
        }
        return classifyBackgroundResponse(data, record);
    }
}

async function notifyClients(message) {
    var clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    clients.forEach(function (client) { client.postMessage(message); });
}

function clearNamedIndexedDBStore(databaseName, storeName) {
    return new Promise(function (resolve) {
        if (!self.indexedDB) { resolve(false); return; }
        var request;
        try { request = self.indexedDB.open(databaseName); }
        catch (error) { resolve(false); return; }
        request.onupgradeneeded = function (event) {
            var database = event.target.result;
            if (!database.objectStoreNames.contains(storeName)) {
                try { event.target.transaction.abort(); } catch (abortError) {}
            }
        };
        request.onerror = function () { resolve(false); };
        request.onsuccess = function () {
            var database = request.result;
            if (!database.objectStoreNames.contains(storeName)) { database.close(); resolve(false); return; }
            var transaction;
            try {
                transaction = database.transaction(storeName, 'readwrite');
                transaction.objectStore(storeName).clear();
            } catch (error) { database.close(); resolve(false); return; }
            transaction.oncomplete = function () { database.close(); resolve(true); };
            transaction.onerror = function () { database.close(); resolve(false); };
            transaction.onabort = function () { database.close(); resolve(false); };
        };
        request.onblocked = function () { resolve(false); };
    });
}

async function invalidateBackgroundApiCaches(database) {
    var cleared = false;
    try {
        if (database && database.objectStoreNames.contains('api_cache')) {
            await new Promise(function (resolve) {
                var transaction;
                try {
                    transaction = database.transaction('api_cache', 'readwrite');
                    transaction.objectStore('api_cache').clear();
                } catch (error) { resolve(false); return; }
                transaction.oncomplete = function () { resolve(true); };
                transaction.onerror = function () { resolve(false); };
                transaction.onabort = function () { resolve(false); };
            }).then(function (result) { if (result) cleared = true; });
        }
    } catch (error) {}
    // The page-level SWR table is a separate existing IndexedDB database. Open it
    // without a version or migration and clear only its existing response store.
    try { if (await clearNamedIndexedDBStore('WSM_API_SWR_DB', 'responses')) cleared = true; } catch (error) {}
    return cleared;
}

async function registerAnotherBackgroundSync() {
    if (!self.registration || !self.registration.sync || typeof self.registration.sync.register !== 'function') return false;
    try {
        await self.registration.sync.register(SYNC_TAG);
        return true;
    } catch (error) {
        return false;
    }
}

function membershipBackgroundDependencyReady(record, activeMemberDependencies) {
    var payload = parseQueuePayload(record) || {}, memberUUID = String(payload.member_uuid || '');
    if (!memberUUID) return Number(payload.member_id || 0) > 0;
    var dependency = activeMemberDependencies && activeMemberDependencies.get(memberUUID);
    return !dependency || dependency.activeIds.size === 0;
}

function releaseBackgroundMemberDependency(activeMemberDependencies, record) {
    if (String(record && record.action_type || '').toUpperCase() !== 'CREATE_MEMBER') return;
    var memberUUID = queueMemberUUID(record), queueId = String(record && (record.id || record.queue_id) || '').trim();
    if (!memberUUID || !queueId) return;
    var dependency = activeMemberDependencies && activeMemberDependencies.get(memberUUID);
    if (!dependency) return;
    dependency.activeIds.delete(queueId);
    dependency.pendingIds.delete(queueId);
    dependency.nonPendingIds.delete(queueId);
    if (!dependency.activeIds.size) activeMemberDependencies.delete(memberUUID);
}

async function runBackgroundOutboxSync() {
    var database = await openLocalDatabase();
    var counts = { synced: 0, failed: 0, pending: 0, auth_required: false, auth_blocked: false, stock_conflicts: [] };
    try {
        var auth = await readRecord(database, 'settings', AUTH_SETTING_KEY);
        if (!auth || auth.disabled || !auth.access_token || !auth.signing_key || !auth.api_base) {
            await safeLog(database, { type: 'background_sync_skipped', message: 'توکن یا کلید امضای ذخیره‌شده برای Background Sync موجود نیست.' });
            return counts;
        }
        var recovered = await recoverStaleProcessing(database);
        if (recovered > 0) await safeLog(database, { type: 'processing_recovered', count: recovered, message: 'پردازش‌های قدیمی در Service Worker به صف انتظار بازگردانده شدند.' });
        await safeLog(database, { type: 'background_sync_started', message: 'Background Sync صف آفلاین آغاز شد.' });
        await notifyClients({ type: 'WSM_SW_SYNC_STARTED' });
        // Keep only a bounded oldest-first page in memory. The active dependency
        // map stores queue IDs only, not full sale/member payloads.
        var queuePage = await readBackgroundQueuePage(database, MAX_BACKGROUND_ITEMS);
        var activeMemberDependencies = queuePage.dependencies;
        var pending = queuePage.records;
        pending.sort(function (a, b) {
            var timeA = Number(a && a.created_at) || Date.parse(a && a.created_at) || 0;
            var timeB = Number(b && b.created_at) || Date.parse(b && b.created_at) || 0;
            if (timeA !== timeB) return timeA - timeB;
            var actionA = String(a && a.action_type || '').toUpperCase(), actionB = String(b && b.action_type || '').toUpperCase();
            var payloadA = parseQueuePayload(a) || {}, payloadB = parseQueuePayload(b) || {};
            var uuidA = String(payloadA.member_uuid || payloadA.uuid || ''), uuidB = String(payloadB.member_uuid || payloadB.uuid || '');
            var dependencyPriority = { CREATE_MEMBER: 0, CREATE_MEMBERSHIP: 1, CREATE_SALE: 2 };
            var priorityA = Object.prototype.hasOwnProperty.call(dependencyPriority, actionA) ? dependencyPriority[actionA] : 9;
            var priorityB = Object.prototype.hasOwnProperty.call(dependencyPriority, actionB) ? dependencyPriority[actionB] : 9;
            if (uuidA && uuidA === uuidB && priorityA !== priorityB) return priorityA - priorityB;
            return String(a && (a.id || a.queue_id) || '').localeCompare(String(b && (b.id || b.queue_id) || ''));
        });
        var owner = 'sw-' + createNonce();
        var networkRetryPending = false;
        var processedCount = 0;
        var syncStartedAt = Date.now();
        for (var index = 0; index < pending.length && processedCount < MAX_BACKGROUND_ITEMS; index++) {
            if (Date.now() - syncStartedAt >= BACKGROUND_SYNC_TIME_BUDGET_MS) break;
            var queued = pending[index];
            var queueId = String(queued.id || queued.queue_id || '').trim();
            if (!queueId) continue;
            var dependencyAction = String(queued.action_type || '').toUpperCase();
            if ((dependencyAction === 'CREATE_MEMBERSHIP' || dependencyAction === 'CREATE_SALE') && !membershipBackgroundDependencyReady(queued, activeMemberDependencies)) {
                counts.pending++;
                await safeLog(database, { type: 'member_dependent_item_waiting', queue_id: queueId, message: dependencyAction === 'CREATE_MEMBERSHIP' ? 'ثبت عضویت تا تعیین تکلیف همگام‌سازی ورزشکار در صف ماند.' : 'فروش وابسته تا تعیین تکلیف همگام‌سازی ورزشکار در صف ماند.' });
                continue;
            }
            var claimed = await claimQueueItem(database, queueId, owner);
            if (!claimed) {
                // Another context may have claimed or completed this item. A later
                // sync pass re-reads the authoritative IndexedDB state.
                continue;
            }
            processedCount++;
            var outcome;
            try {
                outcome = await postSyncItem(database, auth, claimed);
            } catch (error) {
                if (error.blocked) {
                    outcome = { kind: 'pending', error: error.message || 'احراز هویت/مجوز لازم است؛ آیتم در صف باقی ماند.', status: error.status || 0, blocked: true, auth_required: Number(error.status) === 401 };
                } else if (error.serverError || Number(error.status) > 0) {
                    var errorCount = Math.max(0, Number(claimed.server_error_count) || 0) + 1;
                    var errorMessage = String(error.message || 'خطای پاسخ سرور.').slice(0, 1000);
                    var persisted = await updateProcessingQueueItem(database, queueId, owner, {
                        server_error_count: errorCount,
                        last_server_error_at: Date.now(),
                        last_error: errorMessage,
                        last_error_code: String(error.code || '').slice(0, 120),
                        last_http_status: Number(error.status) || 0
                    });
                    if (!persisted) {
                        outcome = { kind: 'pending', error: 'ذخیرهٔ وضعیت خطای سرور انجام نشد؛ آیتم در صف باقی ماند.', status: error.status || 0 };
                    } else {
                        claimed.server_error_count = errorCount;
                        var stockConflict = isInventoryConflict(error.code, error.message);
                        if (stockConflict) {
                            outcome = { kind: 'failed', error: errorMessage, code: String(error.code || ''), status: Number(error.status) || 0, stockConflict: true };
                        } else if (!error.retryable || errorCount > MAX_SERVER_RETRIES) {
                            outcome = { kind: 'failed', error: errorCount > MAX_SERVER_RETRIES ? 'خطای سرور پس از بیش از سه تلاش مجدد؛ آیتم به صف خطا منتقل شد. ' + errorMessage : errorMessage, code: String(error.code || ''), status: Number(error.status) || 0 };
                        } else {
                            outcome = { kind: 'pending', error: errorMessage, status: Number(error.status) || 0, retry: true };
                        }
                        await safeLog(database, { type: 'server_error', queue_id: queueId, message: errorMessage, http_status: Number(error.status) || 0 });
                    }
                } else {
                    outcome = { kind: 'pending', error: error.message || 'خطای شبکه؛ آیتم در صف باقی ماند.', networkError: true };
                }
            }
            if (outcome.kind === 'processed' || outcome.kind === 'duplicate') {
                var deleted = await writeQueueItem(database, queueId, owner, { sync_result: outcome.data && typeof outcome.data === 'object' ? outcome.data : {} }, true);
                if (deleted) {
                    counts.synced++;
                    releaseBackgroundMemberDependency(activeMemberDependencies, claimed);
                    await safeLog(database, { type: 'background_item_synced', queue_id: queueId, message: outcome.kind === 'duplicate' ? 'سرور این queue_id را قبلاً پردازش کرده بود.' : 'آیتم با Background Sync همگام شد.' });
                }
                continue;
            }
            if (outcome.kind === 'failed') {
                var failedMessage = String(outcome.error || 'پردازش آیتم در سرور ناموفق بود.').slice(0, 2000);
                var isStockConflict = !!outcome.stockConflict || isInventoryConflict(outcome.code, outcome.error);
                var failure = {
                    status: 'failed',
                    queue_id: String(claimed.queue_id || queueId),
                    error_message: failedMessage,
                    last_error: failedMessage,
                    error_code: String(outcome.code || claimed.last_error_code || '').slice(0, 120),
                    http_status: Number(outcome.status || claimed.last_http_status || 0) || 0,
                    stock_conflict: isStockConflict,
                    failed_at: Date.now(),
                    updated_at: Date.now()
                };
                var savedFailed = await writeQueueItem(database, queueId, owner, failure, false);
                if (savedFailed) {
                    counts.failed++;
                    var productIds = isStockConflict ? productIdsForRecord(claimed) : [];
                    await safeLog(database, {
                        type: isStockConflict ? 'stock_conflict' : 'background_item_failed',
                        queue_id: queueId,
                        message: failedMessage,
                        http_status: Number(outcome.status || 0),
                        product_ids: productIds,
                        stock_conflict: isStockConflict
                    });
                    if (isStockConflict) counts.stock_conflicts.push({ queue_id: queueId, product_ids: productIds, message: failedMessage, stock_conflict: true });
                }
                continue;
            }
            var pendingMessage = String(outcome.error || 'اتصال موقتاً در دسترس نیست؛ آیتم در صف باقی ماند.').slice(0, 1000);
            var pendingSaved = await writeQueueItem(database, queueId, owner, {
                status: 'pending',
                last_error: pendingMessage,
                last_attempt_at: Date.now(),
                updated_at: Date.now()
            }, false);
            if (pendingSaved) {
                counts.pending++;
                if (outcome.blocked || outcome.auth_required || Number(outcome.status) === 401) counts.auth_blocked = true;
                if (outcome.auth_required || Number(outcome.status) === 401) counts.auth_required = true;
                await safeLog(database, {
                    type: outcome.networkError ? 'network_error' : 'background_item_pending',
                    queue_id: queueId,
                    message: pendingMessage,
                    http_status: Number(outcome.status || 0)
                });
            }
            if (outcome.retry || outcome.networkError) {
                networkRetryPending = true;
                break;
            }
        }
        if (counts.synced > 0) {
            var cachesInvalidated = await invalidateBackgroundApiCaches(database);
            if (!cachesInvalidated) await safeLog(database, { type: 'api_cache_invalidation_deferred', message: 'پاکسازی کش پس از Background Sync کامل نشد؛ در اولین اجرای برنامه دوباره تلاش می‌شود.' });
        }
        var remaining = await countRecordsByIndex(database, 'sync_queue', 'status', 'pending');
        await safeLog(database, { type: 'background_sync_finished', count: counts.synced, message: 'Background Sync پایان یافت؛ ' + counts.synced + ' موفق، ' + counts.failed + ' خطا، ' + remaining + ' در انتظار.' });
        // Don't wake the device repeatedly when every remaining item is blocked
        // on a failed/processing parent. The foreground/manual path can retry it.
        var hasRunnablePage = pending.length > 0;
        if (networkRetryPending) {
            if (remaining > 0 && hasRunnablePage && !counts.auth_blocked) await registerAnotherBackgroundSync();
            await notifyClients({ type: 'WSM_SW_SYNC_COMPLETE', counts: counts, pending: remaining });
            // Rejection signals retry to the browser; its retry schedule is UA-controlled.
            throw new Error('Background Sync paused after a retryable network/server response.');
        }
        if (remaining > 0 && hasRunnablePage && !counts.auth_blocked) await registerAnotherBackgroundSync();
        await notifyClients({ type: 'WSM_SW_SYNC_COMPLETE', counts: counts, pending: remaining });
        return counts;
    } finally {
        database.close();
    }
}

self.addEventListener('sync', function (event) {
    if (!event || (event.tag !== SYNC_TAG && event.tag !== LEGACY_SYNC_TAG)) return;
    event.waitUntil(runBackgroundOutboxSync().catch(async function (error) {
        try {
            var database = await openLocalDatabase();
            await safeLog(database, { type: 'background_sync_error', message: String(error && error.message || 'Background Sync failed.').slice(0, 500) });
            await notifyClients({ type: 'WSM_SW_SYNC_COMPLETE', error: true });
            database.close();
        } catch (logError) {}
        throw error;
    }));
});

async function fetchPeriodicSyncDelta(database, auth, lastSync) {
    var endpoint = new URL(endpointFor(auth, 'sync/pull'), self.location.href);
    endpoint.searchParams.set('last_sync', String(lastSync));

    for (var attempt = 0; attempt < 2; attempt++) {
        var response;
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeout = null;
        if (controller) timeout = setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS);
        try {
            response = await fetch(endpoint.href, {
                method: 'GET',
                headers: await signedSyncGetHeaders(auth, 'sync/pull'),
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller ? controller.signal : undefined
            });
        } finally {
            if (timeout) clearTimeout(timeout);
        }

        if (response.status === 401 && attempt === 0 && await refreshBackgroundAuth(database, auth)) {
            continue;
        }
        var text = await response.text();
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (error) {}
        if (!response.ok) {
            var failure = new Error(data && data.message ? String(data.message) : 'Periodic signed Delta Sync returned HTTP ' + response.status + '.');
            failure.status = response.status;
            throw failure;
        }
        if (!data || typeof data !== 'object' || !Array.isArray(data.products) || !Array.isArray(data.members) || !Array.isArray(data.deleted_ids)) {
            throw new Error('Periodic Delta Sync returned an invalid payload.');
        }
        var watermark = Number(data.sync_time);
        if (!Number.isSafeInteger(watermark) || watermark < 0) {
            throw new Error('Periodic Delta Sync returned an invalid watermark.');
        }
        if (text.length > 5 * 1024 * 1024) {
            throw new Error('Periodic Delta Sync payload exceeded the 5 MB safety limit.');
        }
        return data;
    }
    throw new Error('Periodic Delta Sync could not refresh its authentication token.');
}

async function runPeriodicInventorySync() {
    var database = await openLocalDatabase();
    try {
        var pending = await readRecord(database, 'settings', PENDING_DELTA_KEY);
        if (pending && pending.data) {
            await safeLog(database, { type: 'periodic_inventory_skipped', message: 'تغییرات دوره‌ای قبلی تا اعمال‌شدن در اپ حفظ شدند.' });
            return { skipped: 'pending-delta' };
        }

        var watermarkRecord = await readRecord(database, 'settings', LAST_SYNC_WATERMARK_KEY);
        var lastSync = Number(watermarkRecord && (watermarkRecord.value || watermarkRecord.last_sync || watermarkRecord.data)) || 0;
        if (!Number.isSafeInteger(lastSync) || lastSync < 1) {
            await safeLog(database, { type: 'periodic_inventory_skipped', message: 'همگام‌سازی دوره‌ای تا ثبت نخستین watermark در اپ آغاز نمی‌شود.' });
            return { skipped: 'no-watermark' };
        }

        var auth = await readRecord(database, 'settings', AUTH_SETTING_KEY);
        if (!auth || auth.disabled || !auth.access_token || !auth.signing_key || !auth.api_base) {
            await safeLog(database, { type: 'periodic_inventory_skipped', message: 'اطلاعات امضای دستگاه برای دریافت دوره‌ای موجود نیست.' });
            return { skipped: 'no-auth' };
        }

        var data = await fetchPeriodicSyncDelta(database, auth, lastSync);
        var pendingDelta = {
            key: PENDING_DELTA_KEY,
            last_sync: lastSync,
            sync_time: Number(data.sync_time),
            saved_at: Date.now(),
            data: data
        };
        await saveSetting(database, pendingDelta);
        await safeLog(database, {
            type: 'periodic_inventory_delta_ready',
            count: (data.products.length || 0) + (data.members.length || 0) + (data.deleted_ids.length || 0),
            message: 'تغییرات موجودی به‌صورت امضاشده دریافت شد و برای اعمال امن در پایگاه دادهٔ اپ ذخیره شد.'
        });
        await notifyClients({
            type: 'WSM_SW_PERIODIC_DELTA_READY',
            sync_time: Number(data.sync_time),
            products: data.products.length,
            members: data.members.length,
            deleted: data.deleted_ids.length
        });
        return { sync_time: Number(data.sync_time), products: data.products.length, members: data.members.length };
    } finally {
        database.close();
    }
}

self.addEventListener('periodicsync', function (event) {
    if (!event || event.tag !== PERIODIC_STOCK_SYNC_TAG) return;
    event.waitUntil(runPeriodicInventorySync().catch(async function (error) {
        try {
            var database = await openLocalDatabase();
            await safeLog(database, { type: 'periodic_inventory_error', message: String(error && error.message || 'Periodic inventory sync failed.').slice(0, 500) });
            database.close();
        } catch (logError) {}
        throw error;
    }));
});

function isWordPressApiRequest(url) {
    return /\/wp-json(?:\/|$)/i.test(url.pathname) || url.searchParams.has('rest_route') || /\/wp-admin\/admin-ajax\.php$/i.test(url.pathname);
}

function isAppUIRequest(request, url, scope) {
    if (url.origin !== scope.origin || url.pathname.indexOf(scope.pathname) !== 0) return false;
    if (request.mode === 'navigate') return true;
    var destination = String(request.destination || '').toLowerCase();
    if (['document', 'script', 'style', 'image', 'font', 'manifest', 'worker'].indexOf(destination) !== -1) return true;
    if (destination === 'manifest' || /\/manifest\.json$/i.test(url.pathname)) return true;
    // Arbitrary JSON can contain user/API data; only the static app manifest is cacheable.
    return /\.(?:html?|css|mjs|js|webmanifest|png|jpe?g|webp|gif|svg|ico|woff2?|ttf|otf)$/i.test(url.pathname);
}

function isCacheQuotaError(error) {
    return !!(error && (error.name === 'QuotaExceededError' || error.name === 'NS_ERROR_DOM_QUOTA_REACHED' || Number(error.code) === 22 || Number(error.code) === 1014 || /quota/i.test(String(error.message || ''))));
}

async function notifyCacheQuotaWarning(message) {
    var notice = { type: 'WSM_SW_CACHE_QUOTA_WARNING', message: String(message || 'حافظهٔ آفلاین دستگاه پر شده است؛ همگام‌سازی را اجرا یا فضای دستگاه را آزاد کنید.') };
    try { await notifyClients(notice); } catch (error) {}
}

async function offlineNavigationFallback(cache) {
    var scope = self.registration && self.registration.scope ? self.registration.scope : self.location.href;
    return (await cache.match(new URL('./desktop.html', scope).href)) ||
        (await cache.match(new URL('./index.html', scope).href)) ||
        Response.error();
}

async function cacheFirstUIResponse(request) {
    var cache = await caches.open(CACHE_NAME);
    var cached = await cache.match(request, { ignoreSearch: true });
    var isNavigation = request.mode === 'navigate' || String(request.destination || '').toLowerCase() === 'document';

    // Always revalidate HTML documents online. The previous cache-first behavior
    // could keep an old index.html indefinitely after a plugin update because
    // query strings are ignored for cache matching. Keep the shell cached only
    // as an offline fallback; API requests remain network-only below.
    if (isNavigation) {
        try {
            var freshDocument = await fetch(request, { cache: 'no-store' });
            if (freshDocument && freshDocument.ok && freshDocument.type !== 'opaque') {
                try { await cache.put(request, freshDocument.clone()); }
                catch (cacheError) {
                    if (isCacheQuotaError(cacheError)) await notifyCacheQuotaWarning();
                    else if (self.console && self.console.warn) self.console.warn('[WSM SW] Refreshed app document was not cached.', cacheError);
                }
            }
            return freshDocument;
        } catch (networkError) {
            if (cached) return cached;
            var fallback = await offlineNavigationFallback(cache);
            if (fallback && fallback.type !== 'error') return fallback;
            throw networkError;
        }
    }

    if (cached) return cached;
    try {
        var response = await fetch(request);
        if (response && response.ok && response.type !== 'opaque') {
            try { await cache.put(request, response.clone()); }
            catch (cacheError) {
                if (isCacheQuotaError(cacheError)) await notifyCacheQuotaWarning();
                else if (self.console && self.console.warn) self.console.warn('[WSM SW] Static UI response was not cached.', cacheError);
            }
        }
        return response;
    } catch (error) {
        if (request.mode === 'navigate') {
            var fallback = await offlineNavigationFallback(cache);
            if (fallback && fallback.type !== 'error') return fallback;
        }
        throw error;
    }
}

function hasSensitiveImageCredentials(request, url) {
    if (url.protocol !== 'https:' || request.credentials === 'include') return true;
    if (request.headers && (request.headers.has('authorization') || request.headers.has('cookie') || request.headers.has('x-wsm-token'))) return true;
    var sensitive = /(?:^|[-_])(?:access.?token|refresh.?token|token|auth(?:orization)?|api.?key|key(?:.?id)?|signature|sig|nonce|ticket|credential|session|jwt|awsaccesskeyid)(?:$|[-_])/i;
    var params = new URLSearchParams(url.search || '');
    var found = false;
    params.forEach(function (value, name) { if (sensitive.test(name)) found = true; });
    return found;
}

function isCacheableImageResponse(response) {
    if (!response) return false;
    if (response.type === 'opaque') return true;
    return response.ok && /^image\//i.test(String(response.headers && response.headers.get('content-type') || ''));
}

async function trimOldestImageCacheEntries(cache, keepRequest, maximumToRemove) {
    var keys = await cache.keys();
    var removed = 0;
    var keepUrl = keepRequest && keepRequest.url ? keepRequest.url : '';
    for (var index = 0; index < keys.length && removed < maximumToRemove; index++) {
        if (keepUrl && keys[index].url === keepUrl) continue;
        if (await cache.delete(keys[index])) removed++;
    }
    return removed;
}

async function putCrossOriginImage(cache, request, response) {
    try {
        await cache.put(request, response.clone());
    } catch (cacheError) {
        if (isCacheQuotaError(cacheError)) {
            try {
                await trimOldestImageCacheEntries(cache, request, 12);
                await cache.put(request, response.clone());
            } catch (retryError) {
                await notifyCacheQuotaWarning();
                return false;
            }
        } else {
            if (self.console && self.console.warn) self.console.warn('[WSM SW] Cross-origin image was not cached.', cacheError);
            return false;
        }
    }
    try {
        var keys = await cache.keys();
        if (keys.length > MAX_CROSS_ORIGIN_IMAGE_ENTRIES) {
            await trimOldestImageCacheEntries(cache, request, keys.length - MAX_CROSS_ORIGIN_IMAGE_ENTRIES);
        }
    } catch (trimError) {}
    return true;
}

async function cacheFirstCrossOriginImage(request) {
    var cache = null, cached = null;
    try {
        cache = await caches.open(IMAGE_CACHE_NAME);
        cached = await cache.match(request);
    } catch (cacheError) {
        cache = null;
    }
    if (cached) return cached;
    try {
        var response = await fetch(request);
        if (cache && isCacheableImageResponse(response)) await putCrossOriginImage(cache, request, response);
        return response;
    } catch (error) {
        if (cached) return cached;
        throw error;
    }
}

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (!request || request.method !== 'GET') return;
    var url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        // Cache only public HTTPS image requests; never cache arbitrary third-party
        // data, authenticated requests, or URLs carrying token/signature parameters.
        if (String(request.destination || '').toLowerCase() === 'image' && !isWordPressApiRequest(url) && !hasSensitiveImageCredentials(request, url)) {
            event.respondWith(cacheFirstCrossOriginImage(request));
        }
        return;
    }

    // WordPress REST/API is strictly network-only. No cached API response is
    // consulted, even when an older worker or cache contains one.
    if (isWordPressApiRequest(url)) {
        event.respondWith(fetch(request, { cache: 'no-store' }));
        return;
    }

    var scope = new URL(self.registration && self.registration.scope ? self.registration.scope : self.location.href);
    if (!isAppUIRequest(request, url, scope)) return;
    event.respondWith(cacheFirstUIResponse(request));
});
