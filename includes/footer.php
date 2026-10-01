    </main>

    <!-- Global Auto Setup Modal -->
    <div id="autoSetupModal" class="modal-backdrop">
        <div class="modal-dialog" id="autoSetupModalDialog" style="max-width: 580px;">
            <div class="modal-header">
                <div class="modal-title">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span>1-Click Auto Setup</span>
                </div>
                <div class="modal-header-actions">
                    <button type="button" class="modal-tool-btn" id="btnFullscreenAutoSetup" onclick="toggleModalFullscreen('autoSetupModalDialog', this)" title="Toggle Fullscreen">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                    </button>
                    <button type="button" onclick="closeModal('autoSetupModal')" class="modal-close">&times;</button>
                </div>
            </div>

            <form method="POST" action="/dashboard/index.php" enctype="multipart/form-data" id="autoSetupForm" onsubmit="handleAutoSetupSubmit(event)">
                <input type="hidden" name="action" id="autoSetupActionField" value="auto_setup">
                <input type="hidden" name="setup_source_type" id="autoSetupSourceType" value="zip">

                <div class="modal-body">
                    <p style="font-size: 0.8rem; color: var(--text-muted); line-height: 1.4;">
                        Upload your project files (.zip or folder) along with an optional .sql file. The system will extract files, create the database, patch configs, and launch your app.
                    </p>

                    <!-- Source Selection: ZIP or Folder -->
                    <div class="tab-nav">
                        <button type="button" class="tab-btn active" id="btnAutoZipTab" onclick="toggleAutoSource('zip')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                            <span>ZIP Archive</span>
                        </button>
                        <button type="button" class="tab-btn" id="btnAutoFolderTab" onclick="toggleAutoSource('folder')">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                            <span>Project Folder</span>
                        </button>
                    </div>

                    <!-- ZIP Dropzone -->
                    <div id="autoZipZone">
                        <label class="dropzone" style="display:block; padding: 14px 16px;">
                            <div class="dropzone-icon text-indigo-500" style="margin-bottom: 4px;">
                                <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                            </div>
                            <div style="font-weight: 600; font-size: 0.84rem; color: var(--text-primary);" id="autoZipDropText">Select .ZIP project archive</div>
                            <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;">Upload limit: 256 MB</div>
                            <input type="file" id="setupZipFileInput" name="setup_zip_file" accept=".zip" style="display: none;" onchange="handleAutoZipSelected(this)">
                        </label>
                    </div>

                    <!-- Folder Dropzone -->
                    <div id="autoFolderZone" style="display: none;">
                        <label class="dropzone" style="display:block; padding: 14px 16px;">
                            <div class="dropzone-icon text-indigo-500" style="margin-bottom: 4px;">
                                <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                            </div>
                            <div style="font-weight: 600; font-size: 0.84rem; color: var(--text-primary);" id="autoFolderDropText">Select Entire Project Folder</div>
                            <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;">Click to browse directory tree from your computer</div>
                            <input type="file" id="setupFolderFilesInput" name="setup_folder_files[]" webkitdirectory directory multiple style="display: none;" onchange="handleAutoFolderSelected(this)">
                        </label>
                    </div>

                    <!-- Database Option Toggle -->
                    <div style="background: #f8fafc; border: 1px solid var(--border-light); border-radius: var(--radius-md); padding: 8px 12px; display: flex; items-center; justify-content: space-between;">
                        <label class="checkbox-label" style="margin: 0; font-weight: 600; font-size: 0.82rem; cursor: pointer;">
                            <input type="checkbox" name="setup_enable_db" id="setupEnableDbCheckbox" checked onchange="toggleAutoDbSection(this.checked)">
                            <span>Enable Database Setup (MariaDB)</span>
                        </label>
                        <span id="dbStatusBadge" style="font-size: 0.72rem; font-weight: 600; color: #4f46e5;">Required</span>
                    </div>

                    <!-- Collapsible DB Configuration Section -->
                    <div id="autoDbSection" style="display: flex; flex-direction: column; gap: 10px;">
                        <!-- SQL Dump File (Optional / Auto) -->
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Database .SQL Dump (Optional)</label>
                            <label class="dropzone" style="display:block; padding: 10px 14px;">
                                <div style="font-weight: 500; font-size: 0.8rem; color: var(--text-primary);" id="autoSqlDropText">
                                    Select .SQL file (or will auto-detect from project)
                                </div>
                                <input type="file" name="setup_sql_file" id="setupSqlFileInput" accept=".sql" style="display: none;" onchange="handleAutoSqlSelected(this)">
                            </label>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Project Slug</label>
                                <input type="text" name="setup_project_name" id="autoProjectNameInput" class="form-input" placeholder="auto-derived">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Database Name</label>
                                <input type="text" name="setup_db_name" id="autoDbNameInput" class="form-input" placeholder="auto-derived">
                            </div>
                        </div>

                        <label class="checkbox-label" style="margin-top: 2px;">
                            <input type="checkbox" name="setup_auto_patch" id="setupAutoPatchCheckbox" checked>
                            <span>Auto-patch DB connection config (db.php, config.php, .env)</span>
                        </label>
                    </div>

                    <!-- Standalone No-DB Project Slug -->
                    <div id="noDbSlugSection" class="form-group" style="display: none; margin-bottom: 0;">
                        <label class="form-label">Project Slug (URL Path)</label>
                        <input type="text" name="setup_project_name_nodb" id="autoProjectNameNoDbInput" class="form-input" placeholder="auto-derived">
                        <span class="form-hint" style="color: var(--text-muted); font-size: 0.72rem; margin-top: 3px; display: block;">No database will be created or modified for this project.</span>
                    </div>

                    <!-- Auto Setup Progress Bar, Speed, ETA & Real-Time Timestamp Logs Terminal -->
                    <div id="autoSetupProgressContainer" class="hidden mt-2 p-3 bg-gray-50 border border-gray-200 rounded-xl flex flex-col gap-2">
                        <div class="flex items-center justify-between text-xs font-semibold text-gray-700">
                            <span id="autoSetupStatusText" class="flex items-center gap-1.5 text-indigo-600">
                                <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                Uploading project files...
                            </span>
                            <div class="flex items-center gap-2">
                                <span id="autoSetupSpeedText" class="text-xs font-mono font-normal text-gray-500">0 KB/s</span>
                                <span id="autoSetupPercentText" class="font-bold text-gray-900 font-mono">0%</span>
                            </div>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                            <div id="autoSetupProgressBar" class="bg-indigo-600 h-2 rounded-full transition-all duration-150" style="width: 0%;"></div>
                        </div>
                        <div class="flex items-center justify-between text-[11px] font-mono text-gray-400">
                            <span id="autoSetupBytesText">0 B / 0 B</span>
                            <span id="autoSetupEtaText">Calculating...</span>
                        </div>
                        
                        <!-- Real-time Timestamped Execution Logs -->
                        <div class="mt-1 border border-gray-200 rounded-lg bg-gray-900 text-gray-100 p-2.5 font-mono text-[11px] overflow-hidden">
                            <div class="flex items-center justify-between text-gray-400 border-b border-gray-800 pb-1.5 mb-1.5">
                                <span class="flex items-center gap-1.5 font-semibold text-gray-300">
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                                    Setup & Execution Logs
                                </span>
                                <span class="text-[10px] text-gray-500">Live Timestamped</span>
                            </div>
                            <div id="autoSetupLogsConsole" class="space-y-1 max-h-36 overflow-y-auto pr-1" style="scrollbar-width: thin;">
                                <div class="text-gray-400"><span class="text-gray-500">--:--:--</span> Ready to initialize setup...</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('autoSetupModal')" class="btn btn-outline" id="btnCancelAutoSetup">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitAutoSetup">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span>Run Auto Setup</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Upload ZIP Modal -->
    <div id="uploadZipModal" class="modal-backdrop">
        <div class="modal-dialog" id="uploadZipModalDialog" style="max-width: 540px;">
            <div class="modal-header">
                <div class="modal-title">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    <span>Deploy Application Archive (.ZIP)</span>
                </div>
                <div class="modal-header-actions">
                    <button type="button" class="modal-tool-btn" id="btnFullscreenDeployZip" onclick="toggleModalFullscreen('uploadZipModalDialog', this)" title="Toggle Fullscreen">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                    </button>
                    <button type="button" onclick="closeModal('uploadZipModal')" class="modal-close">&times;</button>
                </div>
            </div>
            <form id="deployZipForm" method="POST" action="/dashboard/index.php" enctype="multipart/form-data" onsubmit="handleDeployZipSubmit(event)">
                <input type="hidden" name="action" value="upload_zip">
                <div class="modal-body">
                    <label class="dropzone" style="display:block; padding: 16px;">
                        <div class="dropzone-icon text-indigo-500" style="margin-bottom: 4px;">
                            <svg class="w-6 h-6 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        </div>
                        <div style="font-weight:600; font-size:0.86rem; color:var(--text-primary);" id="dropzoneArchiveText">Select or drop .ZIP archive</div>
                        <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Upload limit: 256 MB</div>
                        <input type="file" id="deployZipFileInput" name="zip_file" accept=".zip" style="display:none;" onchange="if(this.files[0]) document.getElementById('dropzoneArchiveText').textContent='Selected: '+this.files[0].name;" required>
                    </label>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Destination Folder Name</label>
                        <input type="text" name="target_folder" class="form-input" placeholder="e.g. my-app (leave empty to use zip name)">
                    </div>

                    <!-- ZIP Deploy Progress Bar & Live Logs -->
                    <div id="deployZipProgressContainer" class="hidden mt-2 p-3 bg-gray-50 border border-gray-200 rounded-xl flex flex-col gap-2">
                        <div class="flex items-center justify-between text-xs font-semibold text-gray-700">
                            <span id="deployZipStatusText" class="flex items-center gap-1.5 text-indigo-600">
                                <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                Uploading ZIP archive...
                            </span>
                            <div class="flex items-center gap-2">
                                <span id="deployZipSpeedText" class="text-xs font-mono font-normal text-gray-500">0 KB/s</span>
                                <span id="deployZipPercentText" class="font-bold text-gray-900 font-mono">0%</span>
                            </div>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                            <div id="deployZipProgressBar" class="bg-indigo-600 h-2 rounded-full transition-all duration-150" style="width: 0%;"></div>
                        </div>
                        <div class="flex items-center justify-between text-[11px] font-mono text-gray-400">
                            <span id="deployZipBytesText">0 B / 0 B</span>
                            <span id="deployZipEtaText">Calculating...</span>
                        </div>

                        <!-- Real-time Timestamped Logs Terminal -->
                        <div class="mt-1 border border-gray-200 rounded-lg bg-gray-900 text-gray-100 p-2.5 font-mono text-[11px] overflow-hidden">
                            <div class="flex items-center justify-between text-gray-400 border-b border-gray-800 pb-1.5 mb-1.5">
                                <span class="flex items-center gap-1.5 font-semibold text-gray-300">
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                                    Deployment Logs
                                </span>
                                <span class="text-[10px] text-gray-500">Live Timestamped</span>
                            </div>
                            <div id="deployZipLogsConsole" class="space-y-1 max-h-36 overflow-y-auto pr-1" style="scrollbar-width: thin;">
                                <div class="text-gray-400"><span class="text-gray-500">--:--:--</span> Ready to upload...</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('uploadZipModal')" class="btn btn-outline" id="btnCancelDeployZip">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitDeployZip">Deploy Archive</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Create Database Modal -->
    <div id="createDbModal" class="modal-backdrop">
        <div class="modal-dialog" style="max-width: 480px;">
            <div class="modal-header">
                <div class="modal-title">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                    <span>Create Database</span>
                </div>
                <button type="button" onclick="closeModal('createDbModal')" class="modal-close">&times;</button>
            </div>
            <form method="POST" action="/dashboard/database.php">
                <input type="hidden" name="create_database" value="1">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Database Name</label>
                        <input type="text" name="db_name" class="form-input" placeholder="e.g. ecommerce_db, cms_db" required autofocus>
                        <span class="form-hint">Letters, numbers, and underscores only.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('createDbModal')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Database</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Import SQL File -->
    <div id="importSqlModal" class="modal-backdrop">
        <div class="modal-dialog" style="max-width: 500px;">
            <div class="modal-header">
                <div class="modal-title">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    <span>Import SQL Dump</span>
                </div>
                <button type="button" onclick="closeModal('importSqlModal')" class="modal-close">&times;</button>
            </div>
            <form method="POST" action="/dashboard/index.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_sql">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Target Database</label>
                        <select name="target_db" id="importModalDbSelect" class="form-select">
                            <?php foreach ($dbList as $d): ?>
                                <option value="<?= htmlspecialchars($d) ?>" <?= $d === ($currentDb ?? '') ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Select .SQL File</label>
                        <label class="dropzone" style="display:block; padding:12px 16px;">
                            <div style="font-weight:600; font-size:0.84rem; color:var(--text-primary);" id="dropzoneSqlText">Choose .SQL file</div>
                            <input type="file" name="sql_file" accept=".sql" style="display:none;" onchange="if(this.files[0]) document.getElementById('dropzoneSqlText').textContent='Selected: '+this.files[0].name;" required>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('importSqlModal')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import Database</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Delete Project Confirmation Modal -->
    <div id="deleteProjectModal" class="modal-backdrop">
        <div class="modal-dialog" style="max-width: 440px;">
            <div class="modal-header">
                <div class="modal-title" style="color: #dc2626;">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span>Delete Project</span>
                </div>
                <button type="button" onclick="closeModal('deleteProjectModal')" class="modal-close">&times;</button>
            </div>
            <form method="POST" action="/dashboard/projects.php" id="deleteProjectForm">
                <input type="hidden" name="delete_project" id="deleteProjectTargetInput" value="">
                <div class="modal-body">
                    <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: var(--radius-md); padding: 12px; display: flex; gap: 10px; align-items: flex-start;">
                        <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div style="font-size: 0.8rem; color: #991b1b; line-height: 1.4;">
                            Are you sure you want to delete <strong id="deleteProjectNameDisplay"></strong>? All files in this project directory will be permanently removed.
                        </div>
                    </div>

                    <div id="deleteProjectDbOption" style="display: none; margin-top: 4px;">
                        <label class="checkbox-label" style="font-size: 0.82rem; color: var(--text-primary); cursor: pointer;">
                            <input type="checkbox" name="drop_associated_db" id="dropAssociatedDbCheckbox" value="1">
                            <span>Also drop database <strong id="deleteProjectDbNameDisplay" class="font-mono"></strong></span>
                        </label>
                        <input type="hidden" name="db_name_to_drop" id="deleteProjectDbNameInput" value="">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('deleteProjectModal')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn" style="background: #dc2626; color: #fff; border: 1px solid #dc2626;">Delete Project</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Drop Database Confirmation Modal -->
    <div id="dropDbModal" class="modal-backdrop">
        <div class="modal-dialog" style="max-width: 440px;">
            <div class="modal-header">
                <div class="modal-title" style="color: #dc2626;">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    <span>Drop Database</span>
                </div>
                <button type="button" onclick="closeModal('dropDbModal')" class="modal-close">&times;</button>
            </div>
            <form method="POST" action="/dashboard/database.php">
                <input type="hidden" name="drop_database" value="1">
                <input type="hidden" name="db_name" id="dropDbTargetInput" value="">
                <div class="modal-body">
                    <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: var(--radius-md); padding: 12px; display: flex; gap: 10px; align-items: flex-start;">
                        <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div style="font-size: 0.8rem; color: #991b1b; line-height: 1.4;">
                            Are you sure you want to drop database <strong id="dropDbNameDisplay" class="font-mono"></strong>? All tables, views, and data will be permanently deleted.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('dropDbModal')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn" style="background: #dc2626; color: #fff; border: 1px solid #dc2626;">Drop Database</button>
                </div>
            </form>
        </div>
    </div>

    <footer class="bg-white border-t border-gray-200 py-6 mt-12">
        <div class="container text-center text-xs text-gray-500 font-mono">
            Shriyash Patil Workspace Suite &bull; Built by <a href="https://shriyashpatil.in" target="_blank" class="text-indigo-600 hover:underline font-semibold">Shriyash Patil</a> &bull; PHP <?= $phpVersion ?> &bull; MariaDB 10.11 &bull; Apache 2.4 &bull; Port 8181
        </div>
    </footer>

    <script>
        const availableDatabases = <?= json_encode($dbList ?? []) ?>;

        function openDropDbModal(dbName) {
            const targetInput = document.getElementById('dropDbTargetInput');
            const nameDisplay = document.getElementById('dropDbNameDisplay');
            if (targetInput) targetInput.value = dbName;
            if (nameDisplay) nameDisplay.textContent = dbName;
            openModal('dropDbModal');
        }

        function openDeleteProjectModal(projectName, explicitDbName) {
            const targetInput = document.getElementById('deleteProjectTargetInput');
            const nameDisplay = document.getElementById('deleteProjectNameDisplay');
            const dbOption = document.getElementById('deleteProjectDbOption');
            const dbNameDisplay = document.getElementById('deleteProjectDbNameDisplay');
            const dbNameInput = document.getElementById('deleteProjectDbNameInput');
            const dbCheckbox = document.getElementById('dropAssociatedDbCheckbox');

            if (targetInput) targetInput.value = projectName;
            if (nameDisplay) nameDisplay.textContent = projectName;

            // Check if matching database exists
            const cleanDb1 = projectName.replace(/[^a-zA-Z0-9_]/g, '_');
            const cleanDb2 = cleanDb1 + '_db';
            let matchedDb = null;

            if (explicitDbName && availableDatabases.includes(explicitDbName)) {
                matchedDb = explicitDbName;
            } else if (availableDatabases.includes(cleanDb1)) {
                matchedDb = cleanDb1;
            } else if (availableDatabases.includes(cleanDb2)) {
                matchedDb = cleanDb2;
            }

            if (matchedDb && dbOption && dbNameDisplay && dbNameInput) {
                dbOption.style.display = 'block';
                dbNameDisplay.textContent = matchedDb;
                dbNameInput.value = matchedDb;
                if (dbCheckbox) dbCheckbox.checked = false;
            } else if (dbOption) {
                dbOption.style.display = 'none';
                if (dbNameInput) dbNameInput.value = '';
            }

            openModal('deleteProjectModal');
        }

        function toggleMobileDrawer() {
            const drawer = document.getElementById('mobileDrawer');
            const overlay = document.getElementById('drawerOverlay');
            if (drawer && overlay) {
                const isOpen = drawer.classList.contains('active');
                if (isOpen) {
                    drawer.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                } else {
                    drawer.classList.add('active');
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            }
        }

        function openModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.add('active');
        }
        function closeModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.remove('active');
        }

        function toggleAutoSource(type) {
            const isFolder = (type === 'folder');
            const src = document.getElementById('autoSetupSourceType');
            if (src) src.value = type;
            const zZone = document.getElementById('autoZipZone');
            const fZone = document.getElementById('autoFolderZone');
            if (zZone) zZone.style.display = isFolder ? 'none' : 'block';
            if (fZone) fZone.style.display = isFolder ? 'block' : 'none';
            const bZip = document.getElementById('btnAutoZipTab');
            const bFolder = document.getElementById('btnAutoFolderTab');
            if (bZip) bZip.classList.toggle('active', !isFolder);
            if (bFolder) bFolder.classList.toggle('active', isFolder);
        }

        function toggleAutoDbSection(enable) {
            const dbSection = document.getElementById('autoDbSection');
            const noDbSlugSection = document.getElementById('noDbSlugSection');
            const badge = document.getElementById('dbStatusBadge');
            const sqlInput = document.getElementById('setupSqlFileInput');
            const mainSlug = document.getElementById('autoProjectNameInput');
            const noDbSlug = document.getElementById('autoProjectNameNoDbInput');

            if (enable) {
                if (dbSection) dbSection.style.display = 'flex';
                if (noDbSlugSection) noDbSlugSection.style.display = 'none';
                if (badge) { badge.textContent = 'Required'; badge.style.color = '#4f46e5'; }
                if (noDbSlug && mainSlug && noDbSlug.value && !mainSlug.value) mainSlug.value = noDbSlug.value;
            } else {
                if (dbSection) dbSection.style.display = 'none';
                if (noDbSlugSection) noDbSlugSection.style.display = 'block';
                if (badge) { badge.textContent = 'Disabled (No DB)'; badge.style.color = '#64748b'; }
                if (mainSlug && noDbSlug && mainSlug.value && !noDbSlug.value) noDbSlug.value = mainSlug.value;
                if (sqlInput) sqlInput.value = '';
                const sqlText = document.getElementById('autoSqlDropText');
                if (sqlText) sqlText.textContent = 'Select .SQL file (or will auto-detect from project)';
            }
        }

        function handleAutoZipSelected(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                document.getElementById('autoZipDropText').textContent = 'Selected: ' + file.name;
                const baseName = file.name.replace(/\.[^/.]+$/, "").replace(/[^a-zA-Z0-9_-]/g, '-').toLowerCase();
                const projInput = document.getElementById('autoProjectNameInput');
                const noDbProjInput = document.getElementById('autoProjectNameNoDbInput');
                const dbInput = document.getElementById('autoDbNameInput');
                if (projInput && !projInput.value) {
                    projInput.value = baseName;
                }
                if (noDbProjInput && !noDbProjInput.value) {
                    noDbProjInput.value = baseName;
                }
                if (dbInput && !dbInput.value) {
                    dbInput.value = baseName.replace(/-/g, '_') + '_db';
                }
            }
        }

        function handleAutoFolderSelected(input) {
            if (input.files && input.files.length > 0) {
                const count = input.files.length;
                let rootFolderName = '';
                if (input.files[0].webkitRelativePath) {
                    rootFolderName = input.files[0].webkitRelativePath.split('/')[0];
                }
                document.getElementById('autoFolderDropText').textContent = 'Selected Folder: ' + rootFolderName + ' (' + count + ' files)';
                const cleanName = rootFolderName.replace(/[^a-zA-Z0-9_-]/g, '-').toLowerCase();
                const projInput = document.getElementById('autoProjectNameInput');
                const noDbProjInput = document.getElementById('autoProjectNameNoDbInput');
                const dbInput = document.getElementById('autoDbNameInput');
                if (projInput && !projInput.value && cleanName) {
                    projInput.value = cleanName;
                }
                if (noDbProjInput && !noDbProjInput.value && cleanName) {
                    noDbProjInput.value = cleanName;
                }
                if (dbInput && !dbInput.value && cleanName) {
                    dbInput.value = cleanName.replace(/-/g, '_') + '_db';
                }
                const btn = document.getElementById('btnSubmitAutoSetup');
                if (btn) btn.innerHTML = '<svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg><span>Run Auto Setup (' + count + ' Files)</span>';
            }
        }

        function handleAutoSqlSelected(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                document.getElementById('autoSqlDropText').textContent = 'Selected SQL: ' + file.name;
                const cleanDb = file.name.replace(/\.[^/.]+$/, "").replace(/[^a-zA-Z0-9_]/g, '_').toLowerCase();
                const dbInput = document.getElementById('autoDbNameInput');
                if (dbInput && (!dbInput.value || dbInput.value.endsWith('_db'))) {
                    dbInput.value = cleanDb;
                }
            }
        }

        function toggleModalFullscreen(modalDialogId, btnElement) {
            const dialog = document.getElementById(modalDialogId);
            if (!dialog) return;
            const isFullscreen = dialog.classList.toggle('modal-fullscreen');
            if (btnElement) {
                if (isFullscreen) {
                    btnElement.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9L4 4m0 0l5 5M4 4v5m0-5h5m6 6l5-5m0 0l-5 5m5-5v5m0-5h-5M9 15l-5 5m0 0l5-5m-5 5v-5m0 5h5m6-6l5 5m0 0l-5-5m5 5v-5m0 5h-5"/></svg>';
                    btnElement.title = 'Exit Fullscreen';
                } else {
                    btnElement.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>';
                    btnElement.title = 'Toggle Fullscreen';
                }
            }
        }

        function appendModalLog(consoleId, message, type = 'info') {
            const consoleEl = document.getElementById(consoleId);
            if (!consoleEl) return;
            const d = new Date();
            const timeStr = d.toTimeString().split(' ')[0] + '.' + String(d.getMilliseconds()).padStart(3, '0');
            const row = document.createElement('div');
            row.className = 'flex items-start gap-2 py-0.5 border-b border-gray-800/40 text-[11px] leading-relaxed';
            
            let colorClass = 'text-gray-300';
            let badge = '';
            if (type === 'success') {
                colorClass = 'text-emerald-400 font-medium';
                badge = '<span class="text-emerald-500 font-bold">[SUCCESS]</span> ';
            } else if (type === 'error') {
                colorClass = 'text-red-400 font-medium';
                badge = '<span class="text-red-500 font-bold">[ERROR]</span> ';
            } else if (type === 'warn') {
                colorClass = 'text-amber-300';
                badge = '<span class="text-amber-400">[WARN]</span> ';
            } else if (type === 'step') {
                colorClass = 'text-indigo-300 font-semibold';
                badge = '<span class="text-indigo-400 font-bold">[STEP]</span> ';
            }

            row.innerHTML = `<span class="text-gray-500 flex-shrink-0 select-none">${timeStr}</span> <span class="${colorClass} flex-1 break-all">${badge}${message}</span>`;
            consoleEl.appendChild(row);
            consoleEl.scrollTop = consoleEl.scrollHeight;
        }

        function handleAutoSetupSubmit(e) {
            e.preventDefault();
            const form = document.getElementById('autoSetupForm');
            const srcType = document.getElementById('autoSetupSourceType').value;
            const zipInput = document.getElementById('setupZipFileInput');
            const folderInput = document.getElementById('setupFolderFilesInput');
            const modalDialog = document.getElementById('autoSetupModalDialog');

            if (srcType === 'zip' && (!zipInput.files || !zipInput.files[0])) {
                alert('Please choose a .ZIP archive first.');
                return;
            }
            if (srcType === 'folder' && (!folderInput.files || folderInput.files.length === 0)) {
                alert('Please select a project folder first.');
                return;
            }

            // Expand modal to comfortable size when progress starts
            if (modalDialog && !modalDialog.classList.contains('modal-fullscreen')) {
                modalDialog.classList.add('modal-lg');
            }

            const pContainer = document.getElementById('autoSetupProgressContainer');
            const pBar = document.getElementById('autoSetupProgressBar');
            const pPercent = document.getElementById('autoSetupPercentText');
            const pSpeed = document.getElementById('autoSetupSpeedText');
            const pBytes = document.getElementById('autoSetupBytesText');
            const pEta = document.getElementById('autoSetupEtaText');
            const pStatus = document.getElementById('autoSetupStatusText');
            const logsConsole = document.getElementById('autoSetupLogsConsole');
            const submitBtn = document.getElementById('btnSubmitAutoSetup');
            const cancelBtn = document.getElementById('btnCancelAutoSetup');

            if (logsConsole) logsConsole.innerHTML = '';
            pContainer.classList.remove('hidden');
            setTimeout(() => { pContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 50);
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            cancelBtn.style.display = 'none';

            appendModalLog('autoSetupLogsConsole', 'Initializing 1-Click Auto Setup process...', 'step');
            if (srcType === 'zip') {
                const zf = zipInput.files[0];
                appendModalLog('autoSetupLogsConsole', `Source Archive: ${zf.name} (${formatSpeedBytes(zf.size)})`);
            } else {
                appendModalLog('autoSetupLogsConsole', `Source Folder: ${folderInput.files.length} project files queued.`);
            }

            const enableDb = document.getElementById('setupEnableDbCheckbox')?.checked;
            const sqlInput = document.getElementById('setupSqlFileInput');
            if (enableDb) {
                appendModalLog('autoSetupLogsConsole', 'Database provisioning enabled (MariaDB target)');
                if (sqlInput && sqlInput.files && sqlInput.files[0]) {
                    appendModalLog('autoSetupLogsConsole', `Selected SQL Schema: ${sqlInput.files[0].name}`);
                }
            } else {
                appendModalLog('autoSetupLogsConsole', 'Database provisioning disabled (Standalone project mode)');
            }

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();
            const startTime = Date.now();
            let lastTime = startTime;
            let lastLoaded = 0;
            let logged25 = false, logged50 = false, logged75 = false;

            function formatSpeedBytes(b) {
                if (b === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(b) / Math.log(k));
                return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
            }

            appendModalLog('autoSetupLogsConsole', 'Step 1/3: Uploading payload stream to server...', 'step');

            xhr.upload.addEventListener('progress', function(event) {
                if (event.lengthComputable) {
                    const currentTime = Date.now();
                    const timeDiff = (currentTime - lastTime) / 1000;
                    
                    if (timeDiff >= 0.2 || event.loaded === event.total) {
                        const bytesDiff = event.loaded - lastLoaded;
                        const speed = timeDiff > 0 ? (bytesDiff / timeDiff) : 0;
                        pSpeed.textContent = formatSpeedBytes(speed) + '/s';

                        const remainingBytes = event.total - event.loaded;
                        if (speed > 0 && remainingBytes > 0) {
                            const etaSeconds = Math.ceil(remainingBytes / speed);
                            pEta.textContent = etaSeconds < 60 ? etaSeconds + 's remaining' : Math.ceil(etaSeconds / 60) + 'm remaining';
                        } else if (event.loaded === event.total) {
                            pEta.textContent = 'Uploaded';
                        }

                        lastTime = currentTime;
                        lastLoaded = event.loaded;
                    }

                    const percent = Math.round((event.loaded / event.total) * 100);
                    pBar.style.width = percent + '%';
                    pPercent.textContent = percent + '%';
                    pBytes.textContent = formatSpeedBytes(event.loaded) + ' / ' + formatSpeedBytes(event.total);

                    if (percent >= 25 && !logged25) {
                        logged25 = true;
                        appendModalLog('autoSetupLogsConsole', `Upload progress: 25% completed (${formatSpeedBytes(event.loaded)})`);
                    } else if (percent >= 50 && !logged50) {
                        logged50 = true;
                        appendModalLog('autoSetupLogsConsole', `Upload progress: 50% completed (${formatSpeedBytes(event.loaded)})`);
                    } else if (percent >= 75 && !logged75) {
                        logged75 = true;
                        appendModalLog('autoSetupLogsConsole', `Upload progress: 75% completed (${formatSpeedBytes(event.loaded)})`);
                    }

                    if (percent >= 100) {
                        pStatus.innerHTML = '<svg class="w-4 h-4 animate-spin inline text-indigo-600 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg><span>Extracting files & setting up database...</span>';
                        pEta.textContent = 'Extracting...';
                        appendModalLog('autoSetupLogsConsole', 'Step 2/3: Upload completed. Extracting archives and normalizing directory structure...', 'step');
                        if (enableDb) {
                            appendModalLog('autoSetupLogsConsole', 'Step 3/3: Running SQL migrations & patching database configs...', 'step');
                        }
                    }
                }
            });

            xhr.addEventListener('load', function() {
                let resData = null;
                try {
                    resData = JSON.parse(xhr.responseText);
                } catch(err) {}

                if (xhr.status >= 200 && xhr.status < 300 && (!resData || resData.success !== false)) {
                    pStatus.innerHTML = '<span class="text-emerald-600 font-semibold">Setup & extraction complete!</span>';
                    pBar.classList.remove('bg-indigo-600');
                    pBar.classList.add('bg-emerald-600');
                    pPercent.textContent = '100%';
                    pEta.textContent = 'Completed';

                    // Stream backend extraction logs into console
                    const serverLogs = (resData && Array.isArray(resData.logs)) ? resData.logs : [];
                    let delay = 0;
                    serverLogs.forEach((logItem, lIdx) => {
                        setTimeout(() => {
                            appendModalLog('autoSetupLogsConsole', logItem, logItem.includes('warning') ? 'warn' : 'info');
                        }, delay);
                        delay += 80;
                    });

                    setTimeout(() => {
                        appendModalLog('autoSetupLogsConsole', `Project '${(resData && resData.project_name) ? resData.project_name : 'App'}' ready! Redirecting...`, 'success');
                        const redirectUrl = (resData && resData.redirect) ? resData.redirect : '/dashboard/';
                        setTimeout(() => {
                            window.location.href = redirectUrl;
                        }, 900);
                    }, delay + 200);
                } else {
                    const errorMsg = (resData && resData.error) ? resData.error : ('Error: HTTP ' + xhr.status + ' ' + (xhr.statusText || ''));
                    pStatus.innerHTML = '<span class="text-red-600 font-semibold">Auto setup failed</span>';
                    appendModalLog('autoSetupLogsConsole', errorMsg, 'error');
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    cancelBtn.style.display = '';
                }
            });

            xhr.addEventListener('error', function() {
                pStatus.innerHTML = '<span class="text-red-600 font-semibold">Network connection failed.</span>';
                appendModalLog('autoSetupLogsConsole', 'Network error or upload connection timeout.', 'error');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                cancelBtn.style.display = '';
            });

            const targetUrl = form.getAttribute('action') || '/dashboard/index.php';
            xhr.open('POST', targetUrl, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        }

        function handleDeployZipSubmit(e) {
            e.preventDefault();
            const form = document.getElementById('deployZipForm');
            const zipInput = document.getElementById('deployZipFileInput');
            const modalDialog = document.getElementById('uploadZipModalDialog');

            if (!zipInput.files || !zipInput.files[0]) {
                alert('Please select a .ZIP archive.');
                return;
            }

            // Expand modal to comfortable size when progress starts
            if (modalDialog && !modalDialog.classList.contains('modal-fullscreen')) {
                modalDialog.classList.add('modal-lg');
            }

            const pContainer = document.getElementById('deployZipProgressContainer');
            const pBar = document.getElementById('deployZipProgressBar');
            const pPercent = document.getElementById('deployZipPercentText');
            const pSpeed = document.getElementById('deployZipSpeedText');
            const pBytes = document.getElementById('deployZipBytesText');
            const pEta = document.getElementById('deployZipEtaText');
            const pStatus = document.getElementById('deployZipStatusText');
            const logsConsole = document.getElementById('deployZipLogsConsole');
            const submitBtn = document.getElementById('btnSubmitDeployZip');
            const cancelBtn = document.getElementById('btnCancelDeployZip');

            if (logsConsole) logsConsole.innerHTML = '';
            pContainer.classList.remove('hidden');
            setTimeout(() => { pContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }, 50);
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            cancelBtn.style.display = 'none';

            const zf = zipInput.files[0];
            appendModalLog('deployZipLogsConsole', `Selected archive: ${zf.name} (${formatSpeedBytes(zf.size)})`, 'step');
            appendModalLog('deployZipLogsConsole', 'Step 1/2: Uploading ZIP archive to server...', 'step');

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();
            const startTime = Date.now();
            let lastTime = startTime;
            let lastLoaded = 0;
            let logged25 = false, logged50 = false, logged75 = false;

            function formatSpeedBytes(b) {
                if (b === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(b) / Math.log(k));
                return parseFloat((b / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
            }

            xhr.upload.addEventListener('progress', function(event) {
                if (event.lengthComputable) {
                    const currentTime = Date.now();
                    const timeDiff = (currentTime - lastTime) / 1000;
                    
                    if (timeDiff >= 0.2 || event.loaded === event.total) {
                        const bytesDiff = event.loaded - lastLoaded;
                        const speed = timeDiff > 0 ? (bytesDiff / timeDiff) : 0;
                        pSpeed.textContent = formatSpeedBytes(speed) + '/s';

                        const remainingBytes = event.total - event.loaded;
                        if (speed > 0 && remainingBytes > 0) {
                            const etaSeconds = Math.ceil(remainingBytes / speed);
                            pEta.textContent = etaSeconds < 60 ? etaSeconds + 's remaining' : Math.ceil(etaSeconds / 60) + 'm remaining';
                        } else if (event.loaded === event.total) {
                            pEta.textContent = 'Uploaded';
                        }

                        lastTime = currentTime;
                        lastLoaded = event.loaded;
                    }

                    const percent = Math.round((event.loaded / event.total) * 100);
                    pBar.style.width = percent + '%';
                    pPercent.textContent = percent + '%';
                    pBytes.textContent = formatSpeedBytes(event.loaded) + ' / ' + formatSpeedBytes(event.total);

                    if (percent >= 25 && !logged25) {
                        logged25 = true;
                        appendModalLog('deployZipLogsConsole', `Upload progress: 25% completed (${formatSpeedBytes(event.loaded)})`);
                    } else if (percent >= 50 && !logged50) {
                        logged50 = true;
                        appendModalLog('deployZipLogsConsole', `Upload progress: 50% completed (${formatSpeedBytes(event.loaded)})`);
                    } else if (percent >= 75 && !logged75) {
                        logged75 = true;
                        appendModalLog('deployZipLogsConsole', `Upload progress: 75% completed (${formatSpeedBytes(event.loaded)})`);
                    }

                    if (percent >= 100) {
                        pStatus.innerHTML = '<svg class="w-4 h-4 animate-spin inline text-indigo-600 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg><span>Extracting ZIP archive files...</span>';
                        pEta.textContent = 'Extracting...';
                        appendModalLog('deployZipLogsConsole', 'Step 2/2: Archive uploaded. Extracting files into web directory...', 'step');
                    }
                }
            });

            xhr.addEventListener('load', function() {
                let resData = null;
                try {
                    resData = JSON.parse(xhr.responseText);
                } catch(err) {}

                if (xhr.status >= 200 && xhr.status < 300 && (!resData || resData.success !== false)) {
                    pStatus.innerHTML = '<span class="text-emerald-600 font-semibold">Extraction complete!</span>';
                    pBar.classList.remove('bg-indigo-600');
                    pBar.classList.add('bg-emerald-600');
                    pPercent.textContent = '100%';
                    pEta.textContent = 'Completed';

                    // Stream backend extraction logs
                    const serverLogs = (resData && Array.isArray(resData.logs)) ? resData.logs : [];
                    let delay = 0;
                    serverLogs.forEach((logItem) => {
                        setTimeout(() => {
                            appendModalLog('deployZipLogsConsole', logItem, 'info');
                        }, delay);
                        delay += 80;
                    });

                    setTimeout(() => {
                        appendModalLog('deployZipLogsConsole', 'Extraction and file setup finished successfully!', 'success');
                        appendModalLog('deployZipLogsConsole', 'Redirecting to project list...', 'info');
                        const redirectUrl = (resData && resData.redirect) ? resData.redirect : '/dashboard/projects.php';
                        setTimeout(() => {
                            window.location.href = redirectUrl;
                        }, 900);
                    }, delay + 200);
                } else {
                    const errorMsg = (resData && resData.error) ? resData.error : ('Error: HTTP ' + xhr.status + ' ' + (xhr.statusText || ''));
                    pStatus.innerHTML = '<span class="text-red-600 font-semibold">Deployment failed</span>';
                    appendModalLog('deployZipLogsConsole', errorMsg, 'error');
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    cancelBtn.style.display = '';
                }
            });

            xhr.addEventListener('error', function() {
                pStatus.innerHTML = '<span class="text-red-600 font-semibold">Network connection failed.</span>';
                appendModalLog('deployZipLogsConsole', 'Network connection failed or request timed out.', 'error');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                cancelBtn.style.display = '';
            });

            const deployTargetUrl = form.getAttribute('action') || '/dashboard/index.php';
            xhr.open('POST', deployTargetUrl, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(formData);
        }

        function copyToClipboard(text, btnElement) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    showCopiedFeedback(btnElement);
                }).catch(() => fallbackCopy(text, btnElement));
            } else {
                fallbackCopy(text, btnElement);
            }
        }

        function fallbackCopy(text, btnElement) {
            const tempInput = document.createElement('input');
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            showCopiedFeedback(btnElement);
        }

        function showCopiedFeedback(btnElement) {
            if (!btnElement) return;
            const originalHtml = btnElement.innerHTML;
            btnElement.innerHTML = '<span>Copied!</span>';
            btnElement.style.borderColor = '#10b981';
            btnElement.style.color = '#10b981';
            setTimeout(() => {
                btnElement.innerHTML = originalHtml;
                btnElement.style.borderColor = '';
                btnElement.style.color = '';
            }, 2000);
        }

        // Close modal when clicking outside dialog
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                e.target.classList.remove('active');
            }
            // Close nav dropdowns when clicking outside
            if (!e.target.closest('.nav-dropdown-group')) {
                document.querySelectorAll('.nav-dropdown-group.open').forEach(el => el.classList.remove('open'));
            }
        });

        // Toggle nav dropdown on click
        document.querySelectorAll('.nav-dropdown-trigger').forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const group = this.closest('.nav-dropdown-group');
                const wasOpen = group.classList.contains('open');
                document.querySelectorAll('.nav-dropdown-group.open').forEach(el => el.classList.remove('open'));
                if (!wasOpen) {
                    group.classList.add('open');
                }
            });
        });

        // Close on Escape key
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.nav-dropdown-group.open').forEach(el => el.classList.remove('open'));
            }
        });
    </script>
</body>
</html>
