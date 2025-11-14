/**
 * NOC Scheduler - Main Application
 */

const App = {
    apiUrl: 'api/index.php',
    currentView: 'schedule',
    data: {
        divisions: [],
        desks: [],
        dispatchers: [],
        schedule: {},
        vacancies: [],
        holddowns: [],
        config: {}
    },

    /**
     * Initialize the application
     */
    init: function() {
        this.setupNavigation();
        this.loadInitialData();
        this.showView('schedule');
    },

    /**
     * Setup navigation handlers
     */
    setupNavigation: function() {
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const view = e.target.dataset.view;
                this.showView(view);
            });
        });
    },

    /**
     * Show a specific view
     */
    showView: function(view) {
        // Update active nav button
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.view === view) {
                btn.classList.add('active');
            }
        });

        this.currentView = view;

        // Load the view
        switch(view) {
            case 'schedule':
                this.renderScheduleView();
                break;
            case 'dispatchers':
                this.renderDispatchersView();
                break;
            case 'desks':
                this.renderDesksView();
                break;
            case 'vacancies':
                this.renderVacanciesView();
                break;
            case 'holddowns':
                this.renderHolddownsView();
                break;
            case 'config':
                this.renderConfigView();
                break;
        }
    },

    /**
     * Load initial data
     */
    loadInitialData: async function() {
        try {
            await Promise.all([
                this.loadDivisions(),
                this.loadDesks(),
                this.loadDispatchers(),
                this.loadConfig()
            ]);
        } catch (error) {
            this.showError('Failed to load initial data: ' + error.message);
        }
    },

    /**
     * API call wrapper
     */
    api: async function(action, data = {}) {
        const url = this.apiUrl + '?action=' + action;
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'API request failed');
        }

        return result.data;
    },

    /**
     * Load divisions
     */
    loadDivisions: async function() {
        this.data.divisions = await this.api('divisions_list');
    },

    /**
     * Load desks
     */
    loadDesks: async function() {
        this.data.desks = await this.api('desks_list');
    },

    /**
     * Load dispatchers
     */
    loadDispatchers: async function() {
        this.data.dispatchers = await this.api('dispatchers_list');
    },

    /**
     * Load config
     */
    loadConfig: async function() {
        const configArray = await this.api('config_get');
        this.data.config = {};
        configArray.forEach(item => {
            this.data.config[item.config_key] = item.config_value;
        });
    },

    /**
     * Render Schedule View
     */
    renderScheduleView: function() {
        const today = new Date();
        const startDate = this.formatDate(this.getMonday(today));
        const endDate = this.formatDate(new Date(new Date(startDate).getTime() + 6 * 24 * 60 * 60 * 1000));

        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Weekly Schedule</h2>
                </div>
                <div class="toolbar-right">
                    <input type="date" id="schedule-start-date" value="${startDate}">
                    <button class="btn btn-primary" onclick="App.loadSchedule()">Load Schedule</button>
                </div>
            </div>
            <div id="schedule-container">
                <div class="loading">
                    <div class="spinner"></div>
                    <p>Loading schedule...</p>
                </div>
            </div>
        `;

        document.getElementById('main-content').innerHTML = html;
        this.loadSchedule();
    },

    /**
     * Load and display schedule
     */
    loadSchedule: async function() {
        const startDateInput = document.getElementById('schedule-start-date');
        const startDate = startDateInput ? startDateInput.value : this.formatDate(this.getMonday(new Date()));
        const endDate = this.formatDate(new Date(new Date(startDate).getTime() + 6 * 24 * 60 * 60 * 1000));

        try {
            this.data.schedule = await this.api('schedule_get_range', { start_date: startDate, end_date: endDate });
            this.renderScheduleGrid(startDate);
        } catch (error) {
            this.showError('Failed to load schedule: ' + error.message);
        }
    },

    /**
     * Render schedule grid
     */
    renderScheduleGrid: function(startDate) {
        const days = [];
        const current = new Date(startDate);

        for (let i = 0; i < 7; i++) {
            days.push(new Date(current));
            current.setDate(current.getDate() + 1);
        }

        let html = '<div class="schedule-grid">';

        // Header row
        html += '<div class="schedule-cell schedule-header">Desk / Shift</div>';
        days.forEach(day => {
            html += `<div class="schedule-cell schedule-header">${this.formatDayHeader(day)}</div>`;
        });

        // Group by division and desk
        const groupedDesks = this.groupDesksByDivision();

        Object.keys(groupedDesks).forEach(divisionName => {
            const desks = groupedDesks[divisionName];

            desks.forEach(desk => {
                ['first', 'second', 'third'].forEach(shift => {
                    html += `<div class="schedule-cell schedule-desk">${desk.name} - ${shift.charAt(0).toUpperCase() + shift.slice(1)}</div>`;

                    days.forEach(day => {
                        const dateStr = this.formatDate(day);
                        const assignment = this.getAssignmentForDay(desk.id, shift, dateStr);
                        html += `<div class="schedule-cell">${assignment}</div>`;
                    });
                });
            });
        });

        html += '</div>';
        document.getElementById('schedule-container').innerHTML = html;
    },

    /**
     * Get assignment for a specific day
     */
    getAssignmentForDay: function(deskId, shift, date) {
        const daySchedule = this.data.schedule[date] || [];
        const assignment = daySchedule.find(a => a.desk_id == deskId && a.shift === shift);

        if (!assignment) {
            return '<span class="badge badge-danger">VACANT</span>';
        }

        let className = 'dispatcher-name';
        if (assignment.assignment_type === 'relief') {
            className += ' relief';
        } else if (assignment.assignment_type === 'atw') {
            className += ' atw';
        }

        return `<div class="${className}">${assignment.dispatcher_name || 'Unassigned'}</div>`;
    },

    /**
     * Render Dispatchers View
     */
    renderDispatchersView: function() {
        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Dispatchers</h2>
                </div>
                <div class="toolbar-right">
                    <button class="btn btn-primary" onclick="App.showDispatcherModal()">Add Dispatcher</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Employee #</th>
                        <th>Name</th>
                        <th>Seniority Date</th>
                        <th>Classification</th>
                        <th>Current Assignment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="dispatchers-table-body">
                    ${this.renderDispatchersTable()}
                </tbody>
            </table>
        `;

        document.getElementById('main-content').innerHTML = html;
    },

    /**
     * Render dispatchers table rows
     */
    renderDispatchersTable: function() {
        if (this.data.dispatchers.length === 0) {
            return '<tr><td colspan="7" class="text-center">No dispatchers found</td></tr>';
        }

        return this.data.dispatchers.map(d => `
            <tr>
                <td>${d.seniority_rank}</td>
                <td>${d.employee_number}</td>
                <td>${d.first_name} ${d.last_name}</td>
                <td>${d.seniority_date}</td>
                <td><span class="badge badge-info">${this.formatClassification(d.classification)}</span></td>
                <td><span id="assignment-${d.id}">Loading...</span></td>
                <td>
                    <button class="btn btn-secondary" onclick="App.editDispatcher(${d.id})">Edit</button>
                </td>
            </tr>
        `).join('');
    },

    /**
     * Render Desks View
     */
    renderDesksView: function() {
        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Desks</h2>
                </div>
                <div class="toolbar-right">
                    <button class="btn btn-primary" onclick="App.showDeskModal()">Add Desk</button>
                    <button class="btn btn-secondary" onclick="App.showDivisionModal()">Manage Divisions</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Division</th>
                        <th>Desk Name</th>
                        <th>Code</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${this.renderDesksTable()}
                </tbody>
            </table>
        `;

        document.getElementById('main-content').innerHTML = html;
    },

    /**
     * Render desks table rows
     */
    renderDesksTable: function() {
        if (this.data.desks.length === 0) {
            return '<tr><td colspan="5" class="text-center">No desks found</td></tr>';
        }

        return this.data.desks.map(desk => `
            <tr>
                <td>${desk.division_name}</td>
                <td>${desk.name}</td>
                <td>${desk.code}</td>
                <td>${desk.description || '-'}</td>
                <td>
                    <button class="btn btn-secondary" onclick="App.manageDeskAssignments(${desk.id})">Manage Assignments</button>
                    <button class="btn btn-secondary" onclick="App.editDesk(${desk.id})">Edit</button>
                </td>
            </tr>
        `).join('');
    },

    /**
     * Render Vacancies View
     */
    renderVacanciesView: function() {
        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Vacancies</h2>
                </div>
                <div class="toolbar-right">
                    <button class="btn btn-primary" onclick="App.showVacancyModal()">Create Vacancy</button>
                </div>
            </div>
            <div id="vacancies-container">
                <div class="loading">
                    <div class="spinner"></div>
                    <p>Loading vacancies...</p>
                </div>
            </div>
        `;

        document.getElementById('main-content').innerHTML = html;
        this.loadVacancies();
    },

    /**
     * Load vacancies
     */
    loadVacancies: async function() {
        try {
            this.data.vacancies = await this.api('vacancies_list', {});
            this.renderVacanciesTable();
        } catch (error) {
            this.showError('Failed to load vacancies: ' + error.message);
        }
    },

    /**
     * Render vacancies table
     */
    renderVacanciesTable: function() {
        let html = `
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Desk</th>
                        <th>Shift</th>
                        <th>Type</th>
                        <th>Incumbent</th>
                        <th>Status</th>
                        <th>Filled By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
        `;

        if (this.data.vacancies.length === 0) {
            html += '<tr><td colspan="8" class="text-center">No vacancies found</td></tr>';
        } else {
            this.data.vacancies.forEach(v => {
                html += `
                    <tr>
                        <td>${v.vacancy_date}</td>
                        <td>${v.desk_name}</td>
                        <td>${v.shift}</td>
                        <td>${v.vacancy_type}</td>
                        <td>${v.incumbent_name || '-'}</td>
                        <td><span class="badge badge-${this.getStatusColor(v.status)}">${v.status}</span></td>
                        <td>${v.filled_by_name || '-'}</td>
                        <td>
                            ${v.status === 'pending' ? `<button class="btn btn-success" onclick="App.fillVacancy(${v.id})">Fill</button>` : ''}
                        </td>
                    </tr>
                `;
            });
        }

        html += '</tbody></table>';
        document.getElementById('vacancies-container').innerHTML = html;
    },

    /**
     * Render Hold-downs View
     */
    renderHolddownsView: function() {
        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Hold-Downs</h2>
                </div>
                <div class="toolbar-right">
                    <button class="btn btn-primary" onclick="App.showHolddownModal()">Post Hold-Down</button>
                </div>
            </div>
            <div id="holddowns-container">
                <div class="loading">
                    <div class="spinner"></div>
                    <p>Loading hold-downs...</p>
                </div>
            </div>
        `;

        document.getElementById('main-content').innerHTML = html;
        this.loadHolddowns();
    },

    /**
     * Load hold-downs
     */
    loadHolddowns: async function() {
        try {
            this.data.holddowns = await this.api('holddowns_list', {});
            this.renderHolddownsTable();
        } catch (error) {
            this.showError('Failed to load hold-downs: ' + error.message);
        }
    },

    /**
     * Render hold-downs table
     */
    renderHolddownsTable: function() {
        let html = `
            <table>
                <thead>
                    <tr>
                        <th>Posted</th>
                        <th>Desk</th>
                        <th>Shift</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Incumbent</th>
                        <th>Status</th>
                        <th>Bids</th>
                        <th>Awarded To</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
        `;

        if (this.data.holddowns.length === 0) {
            html += '<tr><td colspan="10" class="text-center">No hold-downs found</td></tr>';
        } else {
            this.data.holddowns.forEach(h => {
                html += `
                    <tr>
                        <td>${this.formatDateTime(h.posted_date)}</td>
                        <td>${h.desk_name}</td>
                        <td>${h.shift}</td>
                        <td>${h.start_date}</td>
                        <td>${h.end_date}</td>
                        <td>${h.incumbent_name}</td>
                        <td><span class="badge badge-${this.getStatusColor(h.status)}">${h.status}</span></td>
                        <td>${h.bid_count}</td>
                        <td>${h.awarded_name || '-'}</td>
                        <td>
                            ${h.status === 'posted' ? `
                                <button class="btn btn-secondary" onclick="App.viewBids(${h.id})">View Bids</button>
                                <button class="btn btn-success" onclick="App.awardHolddown(${h.id})">Award</button>
                            ` : ''}
                        </td>
                    </tr>
                `;
            });
        }

        html += '</tbody></table>';
        document.getElementById('holddowns-container').innerHTML = html;
    },

    /**
     * Render Config View
     */
    renderConfigView: function() {
        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>System Configuration</h2>
                </div>
            </div>
            <div class="grid grid-2">
                <div class="card">
                    <div class="card-header">FRA Hours of Service</div>
                    <div class="form-group">
                        <label>Maximum Duty Hours</label>
                        <input type="number" id="config-fra-max" value="${this.data.config.fra_max_duty_hours || 9}">
                    </div>
                    <div class="form-group">
                        <label>Minimum Rest Hours</label>
                        <input type="number" id="config-fra-min" value="${this.data.config.fra_min_rest_hours || 15}">
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">Extra Board</div>
                    <div class="form-group">
                        <label>Baseline EB Count (for overtime calculation)</label>
                        <input type="number" id="config-eb-baseline" value="${this.data.config.eb_baseline_count || 0}">
                        <small>If EB pool falls below this number, diversions are paid overtime</small>
                    </div>
                </div>
            </div>
            <div class="mt-20">
                <button class="btn btn-primary" onclick="App.saveConfig()">Save Configuration</button>
            </div>
        `;

        document.getElementById('main-content').innerHTML = html;
    },

    /**
     * Save configuration
     */
    saveConfig: async function() {
        try {
            const updates = [
                { key: 'fra_max_duty_hours', value: document.getElementById('config-fra-max').value },
                { key: 'fra_min_rest_hours', value: document.getElementById('config-fra-min').value },
                { key: 'eb_baseline_count', value: document.getElementById('config-eb-baseline').value }
            ];

            for (const update of updates) {
                await this.api('config_update', update);
            }

            this.showSuccess('Configuration saved successfully');
            await this.loadConfig();
        } catch (error) {
            this.showError('Failed to save configuration: ' + error.message);
        }
    },

    /**
     * Fill a vacancy using the order of call engine
     */
    fillVacancy: async function(vacancyId) {
        if (!confirm('Fill this vacancy using the order of call procedure?')) {
            return;
        }

        try {
            const result = await this.api('vacancy_fill', { vacancy_id: vacancyId });
            this.showSuccess('Vacancy filled successfully');

            // Show decision log
            if (result.decision_log) {
                this.showDecisionLog(result.decision_log);
            }

            this.loadVacancies();
        } catch (error) {
            this.showError('Failed to fill vacancy: ' + error.message);
        }
    },

    /**
     * Show decision log modal
     */
    showDecisionLog: function(log) {
        let html = '<div class="card"><div class="card-header">Order of Call Decision Log</div><ul>';
        log.forEach(entry => {
            html += `<li><strong>${entry.timestamp}:</strong> ${entry.message}</li>`;
        });
        html += '</ul></div>';

        alert('Decision log:\n\n' + log.map(e => e.message).join('\n'));
    },

    // ============================================================
    // UTILITY FUNCTIONS
    // ============================================================

    /**
     * Format date as YYYY-MM-DD
     */
    formatDate: function(date) {
        const d = new Date(date);
        const month = '' + (d.getMonth() + 1);
        const day = '' + d.getDate();
        const year = d.getFullYear();

        return [year, month.padStart(2, '0'), day.padStart(2, '0')].join('-');
    },

    /**
     * Format datetime
     */
    formatDateTime: function(datetime) {
        if (!datetime) return '-';
        const d = new Date(datetime);
        return d.toLocaleString();
    },

    /**
     * Format day header
     */
    formatDayHeader: function(date) {
        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        return days[date.getDay()] + ' ' + (date.getMonth() + 1) + '/' + date.getDate();
    },

    /**
     * Get Monday of week
     */
    getMonday: function(date) {
        const d = new Date(date);
        const day = d.getDay();
        const diff = d.getDate() - day + (day === 0 ? -6 : 1);
        return new Date(d.setDate(diff));
    },

    /**
     * Group desks by division
     */
    groupDesksByDivision: function() {
        const grouped = {};
        this.data.desks.forEach(desk => {
            if (!grouped[desk.division_name]) {
                grouped[desk.division_name] = [];
            }
            grouped[desk.division_name].push(desk);
        });
        return grouped;
    },

    /**
     * Format classification
     */
    formatClassification: function(classification) {
        const map = {
            'job_holder': 'Job Holder',
            'extra_board': 'Extra Board',
            'qualifying': 'Qualifying'
        };
        return map[classification] || classification;
    },

    /**
     * Get status color
     */
    getStatusColor: function(status) {
        const map = {
            'pending': 'warning',
            'filled': 'success',
            'unfilled': 'danger',
            'posted': 'info',
            'awarded': 'success',
            'active': 'success',
            'completed': 'secondary',
            'cancelled': 'danger'
        };
        return map[status] || 'secondary';
    },

    /**
     * Show success message
     */
    showSuccess: function(message) {
        alert('✓ ' + message);
    },

    /**
     * Show error message
     */
    showError: function(message) {
        alert('✗ Error: ' + message);
    },

    // Placeholder functions for modals (to be implemented)
    showDispatcherModal: function() { alert('Dispatcher modal not yet implemented'); },
    editDispatcher: function(id) { alert('Edit dispatcher #' + id); },
    showDeskModal: function() { alert('Desk modal not yet implemented'); },
    showDivisionModal: function() { alert('Division modal not yet implemented'); },
    editDesk: function(id) { alert('Edit desk #' + id); },
    manageDeskAssignments: function(id) { alert('Manage assignments for desk #' + id); },
    showVacancyModal: function() { alert('Vacancy modal not yet implemented'); },
    showHolddownModal: function() { alert('Hold-down modal not yet implemented'); },
    viewBids: function(id) { alert('View bids for hold-down #' + id); },
    awardHolddown: async function(id) {
        if (!confirm('Award this hold-down to the most senior bidder?')) return;
        try {
            await this.api('holddown_award', { holddown_id: id });
            this.showSuccess('Hold-down awarded successfully');
            this.loadHolddowns();
        } catch (error) {
            this.showError('Failed to award hold-down: ' + error.message);
        }
    }
};
