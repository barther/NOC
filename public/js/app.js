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

        // Default to desk view if not set
        if (!this.scheduleViewMode) {
            this.scheduleViewMode = 'desk';
        }

        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Weekly Schedule</h2>
                    <div class="btn-group">
                        <button class="btn ${this.scheduleViewMode === 'desk' ? 'btn-primary' : 'btn-secondary'}"
                                onclick="App.switchScheduleView('desk')">By Desk</button>
                        <button class="btn ${this.scheduleViewMode === 'dispatcher' ? 'btn-primary' : 'btn-secondary'}"
                                onclick="App.switchScheduleView('dispatcher')">By Dispatcher</button>
                    </div>
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
     * Switch between desk and dispatcher view
     */
    switchScheduleView: function(mode) {
        this.scheduleViewMode = mode;
        const startDateInput = document.getElementById('schedule-start-date');
        const startDate = startDateInput ? startDateInput.value : this.formatDate(this.getMonday(new Date()));
        this.renderScheduleGrid(startDate);

        // Update button states
        document.querySelectorAll('.btn-group .btn').forEach(btn => {
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-secondary');
        });
        event.target.classList.remove('btn-secondary');
        event.target.classList.add('btn-primary');
    },

    /**
     * Load and display schedule
     */
    loadSchedule: async function() {
        const startDateInput = document.getElementById('schedule-start-date');
        const startDate = startDateInput ? startDateInput.value : this.formatDate(this.getMonday(new Date()));

        // Calculate end date (6 days after start = full week Mon-Sun)
        // Force local timezone to avoid date shifting issues
        const startDateObj = new Date(startDate + 'T00:00:00');
        const endDateObj = new Date(startDateObj);
        endDateObj.setDate(endDateObj.getDate() + 6);
        const endDate = this.formatDate(endDateObj);

        console.log('Loading schedule:', { startDate, endDate });

        try {
            this.data.schedule = await this.api('schedule_get_range', { start_date: startDate, end_date: endDate });
            console.log('Schedule data received:', Object.keys(this.data.schedule).length, 'days');
            this.renderScheduleGrid(startDate);
        } catch (error) {
            this.showError('Failed to load schedule: ' + error.message);
        }
    },

    /**
     * Render schedule grid (dispatcher for view mode)
     */
    renderScheduleGrid: function(startDate) {
        if (this.scheduleViewMode === 'dispatcher') {
            this.renderDispatcherScheduleGrid(startDate);
        } else {
            this.renderDeskScheduleGrid(startDate);
        }
    },

    /**
     * Render desk-centric schedule grid
     */
    renderDeskScheduleGrid: function(startDate) {
        const days = [];
        // Force local timezone to avoid date shifting
        const current = new Date(startDate + 'T00:00:00');

        for (let i = 0; i < 7; i++) {
            days.push(new Date(current));
            current.setDate(current.getDate() + 1);
        }

        console.log('Rendering desk schedule grid for 7 days:', days.map(d => this.formatDate(d)));

        let html = '<div class="schedule-grid">';

        // Header row
        html += '<div class="schedule-cell schedule-header">Desk</div>';
        days.forEach(day => {
            html += `<div class="schedule-cell schedule-header">${this.formatDayHeader(day)}</div>`;
        });

        // Group by division and desk
        const groupedDesks = this.groupDesksByDivision();

        Object.keys(groupedDesks).forEach(divisionName => {
            const desks = groupedDesks[divisionName];

            // Add division header row spanning all columns
            html += `<div class="schedule-cell schedule-division-header" style="grid-column: 1 / -1;">${divisionName}</div>`;

            desks.forEach(desk => {
                // One row per desk showing all three shifts
                html += `<div class="schedule-cell schedule-desk">${desk.name}</div>`;

                days.forEach(day => {
                    const dateStr = this.formatDate(day);

                    // Get assignments for all three shifts
                    const firstAssignment = this.getAssignmentForDay(desk.id, 'first', dateStr);
                    const secondAssignment = this.getAssignmentForDay(desk.id, 'second', dateStr);
                    const thirdAssignment = this.getAssignmentForDay(desk.id, 'third', dateStr);

                    // Combine all shifts in one cell
                    html += `<div class="schedule-cell schedule-multi-shift">
                        <div class="shift-line shift-first" title="First Shift (0600-1400)"><span class="shift-label">1st:</span> ${firstAssignment}</div>
                        <div class="shift-line shift-second" title="Second Shift (1400-2200)"><span class="shift-label">2nd:</span> ${secondAssignment}</div>
                        <div class="shift-line shift-third" title="Third Shift (2200-0600)"><span class="shift-label">3rd:</span> ${thirdAssignment}</div>
                    </div>`;
                });
            });
        });

        html += '</div>';
        document.getElementById('schedule-container').innerHTML = html;
    },

    /**
     * Render dispatcher-centric schedule grid
     */
    renderDispatcherScheduleGrid: function(startDate) {
        const days = [];
        // Force local timezone to avoid date shifting
        const current = new Date(startDate + 'T00:00:00');

        for (let i = 0; i < 7; i++) {
            days.push(new Date(current));
            current.setDate(current.getDate() + 1);
        }

        console.log('Rendering dispatcher schedule grid for 7 days:', days.map(d => this.formatDate(d)));

        let html = '<div class="schedule-grid">';

        // Header row
        html += '<div class="schedule-cell schedule-header">Dispatcher</div>';
        days.forEach(day => {
            html += `<div class="schedule-cell schedule-header">${this.formatDayHeader(day)}</div>`;
        });

        // Get all active dispatchers sorted by seniority
        const dispatchers = this.data.dispatchers.filter(d => d.active).sort((a, b) => a.seniority_rank - b.seniority_rank);

        dispatchers.forEach(dispatcher => {
            // One row per dispatcher
            html += `<div class="schedule-cell schedule-desk">${dispatcher.employee_number} - ${dispatcher.first_name} ${dispatcher.last_name}</div>`;

            days.forEach(day => {
                const dateStr = this.formatDate(day);

                // Find what this dispatcher is assigned to on this day
                const assignments = this.getDispatcherAssignmentsForDay(dispatcher.id, dateStr);

                if (assignments.length === 0) {
                    html += `<div class="schedule-cell"><span class="badge badge-secondary">OFF</span></div>`;
                } else {
                    html += `<div class="schedule-cell schedule-multi-shift">`;
                    assignments.forEach(assignment => {
                        const shiftLabel = assignment.shift === 'first' ? '1st' : assignment.shift === 'second' ? '2nd' : '3rd';
                        const shiftClass = `shift-${assignment.shift}`;
                        html += `<div class="shift-line ${shiftClass}" title="${assignment.shift.charAt(0).toUpperCase() + assignment.shift.slice(1)} Shift">
                            <span class="shift-label">${shiftLabel}:</span> ${assignment.desk_name}
                        </div>`;
                    });
                    html += `</div>`;
                }
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

        if (!assignment || assignment.assignment_type === 'vacancy' || !assignment.dispatcher_name) {
            return '<span class="badge badge-danger">VACANT</span>';
        }

        let className = 'dispatcher-name';
        if (assignment.assignment_type === 'relief') {
            className += ' relief';
        } else if (assignment.assignment_type === 'atw') {
            className += ' atw';
        }

        return `<div class="${className}">${assignment.dispatcher_name}</div>`;
    },

    /**
     * Get all assignments for a specific dispatcher on a specific day
     */
    getDispatcherAssignmentsForDay: function(dispatcherId, date) {
        const daySchedule = this.data.schedule[date] || [];
        const assignments = daySchedule.filter(a => a.assigned_dispatcher_id == dispatcherId && a.assignment_type !== 'vacancy');

        return assignments.map(a => ({
            desk_id: a.desk_id,
            desk_name: a.desk_name,
            shift: a.shift,
            assignment_type: a.assignment_type
        }));
    },

    /**
     * Render Dispatchers View
     */
    renderDispatchersView: function() {
        // Initialize filter/sort state if not exists
        if (!this.dispatcherFilters) {
            this.dispatcherFilters = {
                search: '',
                classification: 'all',
                sortBy: 'seniority'
            };
        }

        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Dispatchers</h2>
                </div>
                <div class="toolbar-right">
                    <button class="btn btn-primary" onclick="App.showDispatcherModal()">Add Dispatcher</button>
                </div>
            </div>
            <div class="filters-bar">
                <div class="filter-group">
                    <label>Search:</label>
                    <input type="text" id="dispatcher-search" placeholder="Name or Employee #"
                           value="${this.dispatcherFilters.search}"
                           oninput="App.updateDispatcherFilter('search', this.value)">
                </div>
                <div class="filter-group">
                    <label>Classification:</label>
                    <select id="dispatcher-classification" onchange="App.updateDispatcherFilter('classification', this.value)">
                        <option value="all" ${this.dispatcherFilters.classification === 'all' ? 'selected' : ''}>All</option>
                        <option value="job_holder" ${this.dispatcherFilters.classification === 'job_holder' ? 'selected' : ''}>Job Holders</option>
                        <option value="extra_board" ${this.dispatcherFilters.classification === 'extra_board' ? 'selected' : ''}>Extra Board</option>
                        <option value="qualifying" ${this.dispatcherFilters.classification === 'qualifying' ? 'selected' : ''}>Qualifying</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Sort By:</label>
                    <select id="dispatcher-sort" onchange="App.updateDispatcherFilter('sortBy', this.value)">
                        <option value="seniority" ${this.dispatcherFilters.sortBy === 'seniority' ? 'selected' : ''}>Seniority</option>
                        <option value="name" ${this.dispatcherFilters.sortBy === 'name' ? 'selected' : ''}>Name</option>
                        <option value="employee_number" ${this.dispatcherFilters.sortBy === 'employee_number' ? 'selected' : ''}>Employee #</option>
                    </select>
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
     * Update dispatcher filter and re-render
     */
    updateDispatcherFilter: function(field, value) {
        this.dispatcherFilters[field] = value;
        document.getElementById('dispatchers-table-body').innerHTML = this.renderDispatchersTable();
    },

    /**
     * Render dispatchers table rows
     */
    renderDispatchersTable: function() {
        if (this.data.dispatchers.length === 0) {
            return '<tr><td colspan="7" class="text-center">No dispatchers found</td></tr>';
        }

        // Apply filters
        let filtered = this.data.dispatchers.filter(d => {
            // Search filter
            if (this.dispatcherFilters && this.dispatcherFilters.search) {
                const search = this.dispatcherFilters.search.toLowerCase();
                const fullName = `${d.first_name} ${d.last_name}`.toLowerCase();
                const empNum = d.employee_number.toString();
                if (!fullName.includes(search) && !empNum.includes(search)) {
                    return false;
                }
            }

            // Classification filter
            if (this.dispatcherFilters && this.dispatcherFilters.classification !== 'all') {
                if (d.classification !== this.dispatcherFilters.classification) {
                    return false;
                }
            }

            return true;
        });

        // Apply sorting
        if (this.dispatcherFilters && this.dispatcherFilters.sortBy) {
            filtered.sort((a, b) => {
                switch(this.dispatcherFilters.sortBy) {
                    case 'seniority':
                        return a.seniority_rank - b.seniority_rank;
                    case 'name':
                        const nameA = `${a.first_name} ${a.last_name}`.toLowerCase();
                        const nameB = `${b.first_name} ${b.last_name}`.toLowerCase();
                        return nameA.localeCompare(nameB);
                    case 'employee_number':
                        return parseInt(a.employee_number) - parseInt(b.employee_number);
                    default:
                        return 0;
                }
            });
        }

        if (filtered.length === 0) {
            return '<tr><td colspan="7" class="text-center">No dispatchers match the current filters</td></tr>';
        }

        return filtered.map(d => `
            <tr>
                <td>${d.seniority_rank}</td>
                <td>${d.employee_number}</td>
                <td>${d.first_name} ${d.last_name}</td>
                <td>${d.seniority_date}</td>
                <td><span class="badge badge-info">${this.formatClassification(d.classification)}</span></td>
                <td><span id="assignment-${d.id}">Loading...</span></td>
                <td>
                    <button class="btn btn-secondary" onclick="App.editDispatcher(${d.id})">Edit</button>
                    <button class="btn btn-danger" onclick="App.deleteDispatcher(${d.id}, '${d.first_name} ${d.last_name}')">Delete</button>
                </td>
            </tr>
        `).join('');
    },

    /**
     * Render Desks View
     */
    renderDesksView: function() {
        // Initialize filter state if not exists
        if (!this.deskFilters) {
            this.deskFilters = {
                search: '',
                division: 'all'
            };
        }

        // Get unique divisions for filter
        const divisions = [...new Set(this.data.desks.map(d => d.division_name))].sort();

        const html = `
            <div class="toolbar">
                <div class="toolbar-left">
                    <h2>Desks</h2>
                </div>
                <div class="toolbar-right">
                    <button class="btn btn-primary" onclick="App.showDeskModal()">Add Desk</button>
                    <button class="btn btn-secondary" onclick="App.showManageDivisionsModal()">Manage Divisions</button>
                </div>
            </div>
            <div class="filters-bar">
                <div class="filter-group">
                    <label>Search:</label>
                    <input type="text" id="desk-search" placeholder="Desk name or code"
                           value="${this.deskFilters.search}"
                           oninput="App.updateDeskFilter('search', this.value)">
                </div>
                <div class="filter-group">
                    <label>Division:</label>
                    <select id="desk-division" onchange="App.updateDeskFilter('division', this.value)">
                        <option value="all" ${this.deskFilters.division === 'all' ? 'selected' : ''}>All Divisions</option>
                        ${divisions.map(div => `
                            <option value="${div}" ${this.deskFilters.division === div ? 'selected' : ''}>${div}</option>
                        `).join('')}
                    </select>
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
                <tbody id="desks-table-body">
                    ${this.renderDesksTable()}
                </tbody>
            </table>
        `;

        document.getElementById('main-content').innerHTML = html;
    },

    /**
     * Update desk filter and re-render
     */
    updateDeskFilter: function(field, value) {
        this.deskFilters[field] = value;
        document.getElementById('desks-table-body').innerHTML = this.renderDesksTable();
    },

    /**
     * Render desks table rows
     */
    renderDesksTable: function() {
        if (this.data.desks.length === 0) {
            return '<tr><td colspan="5" class="text-center">No desks found</td></tr>';
        }

        // Apply filters
        let filtered = this.data.desks.filter(desk => {
            // Search filter
            if (this.deskFilters && this.deskFilters.search) {
                const search = this.deskFilters.search.toLowerCase();
                const name = desk.name.toLowerCase();
                const code = desk.code.toLowerCase();
                if (!name.includes(search) && !code.includes(search)) {
                    return false;
                }
            }

            // Division filter
            if (this.deskFilters && this.deskFilters.division !== 'all') {
                if (desk.division_name !== this.deskFilters.division) {
                    return false;
                }
            }

            return true;
        });

        if (filtered.length === 0) {
            return '<tr><td colspan="5" class="text-center">No desks match the current filters</td></tr>';
        }

        return filtered.map(desk => `
            <tr>
                <td>${desk.division_name}</td>
                <td>${desk.name}</td>
                <td>${desk.code}</td>
                <td>${desk.description || '-'}</td>
                <td>
                    <button class="btn btn-secondary" onclick="App.manageDeskAssignments(${desk.id})">Manage Assignments</button>
                    <button class="btn btn-secondary" onclick="App.editDesk(${desk.id})">Edit</button>
                    <button class="btn btn-danger" onclick="App.deleteDesk(${desk.id}, '${desk.name}')">Delete</button>
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

            <div class="card mt-20">
                <div class="card-header">CSV Data Import</div>
                <div class="card-body">
                    <p>Import dispatcher data from <strong>data.csv</strong> file (must be in the NOC root directory).</p>
                    <p><small>This will import dispatchers, divisions, desks, and assignments from the CSV file.</small></p>
                    <div class="alert alert-warning" style="margin-bottom: 15px;">
                        <strong>Warning:</strong> If you get duplicate errors, click "Clear All Data" first to remove existing dispatchers.
                    </div>
                    <div id="import-status" style="margin: 15px 0;"></div>
                    <button class="btn btn-danger" onclick="App.clearAllData()" style="margin-right: 10px;">Clear All Data</button>
                    <button class="btn btn-secondary" onclick="App.validateCSV()">Validate CSV</button>
                    <button class="btn btn-primary" onclick="App.importCSV()" id="import-btn" disabled>Import Data</button>
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

    /**
     * Show/hide modal
     */
    showModal: function(title, bodyHtml) {
        document.getElementById('modal-title').textContent = title;
        document.getElementById('modal-body').innerHTML = bodyHtml;
        document.getElementById('modal-container').classList.add('active');
    },

    closeModal: function() {
        document.getElementById('modal-container').classList.remove('active');
    },

    /**
     * Show division management modal
     */
    showDivisionModal: function() {
        const html = `
            <form id="division-form" onsubmit="App.submitDivisionForm(event); return false;">
                <div class="form-group">
                    <label>Division Name *</label>
                    <input type="text" name="name" required placeholder="e.g., Northern Division">
                </div>
                <div class="form-group">
                    <label>Division Code *</label>
                    <input type="text" name="code" required placeholder="e.g., NORTH" style="text-transform: uppercase;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Division</button>
                </div>
            </form>
        `;
        this.showModal('Create Division', html);
    },

    submitDivisionForm: async function(event) {
        event.preventDefault();
        const form = event.target;
        const data = {
            name: form.name.value,
            code: form.code.value.toUpperCase()
        };

        try {
            await this.api('division_create', data);
            this.showSuccess('Division created successfully');
            await this.loadDivisions();
            await this.loadDesks();
            this.closeModal();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to create division: ' + error.message);
        }
    },

    /**
     * Show desk modal
     */
    showDeskModal: function() {
        const divisionOptions = this.data.divisions.map(d =>
            `<option value="${d.id}">${d.name} (${d.code})</option>`
        ).join('');

        const html = `
            <form id="desk-form" onsubmit="App.submitDeskForm(event); return false;">
                <div class="form-group">
                    <label>Division *</label>
                    <select name="division_id" required>
                        <option value="">Select a division...</option>
                        ${divisionOptions}
                    </select>
                </div>
                <div class="form-group">
                    <label>Desk Name *</label>
                    <input type="text" name="name" required placeholder="e.g., Northern Main">
                </div>
                <div class="form-group">
                    <label>Desk Code *</label>
                    <input type="text" name="code" required placeholder="e.g., N-MAIN" style="text-transform: uppercase;">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Optional description"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Desk</button>
                </div>
            </form>
        `;
        this.showModal('Create Desk', html);
    },

    submitDeskForm: async function(event) {
        event.preventDefault();
        const form = event.target;
        const data = {
            division_id: form.division_id.value,
            name: form.name.value,
            code: form.code.value.toUpperCase(),
            description: form.description.value
        };

        try {
            await this.api('desk_create', data);
            this.showSuccess('Desk created successfully');
            await this.loadDesks();
            this.closeModal();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to create desk: ' + error.message);
        }
    },

    /**
     * Show dispatcher modal
     */
    showDispatcherModal: function() {
        const html = `
            <form id="dispatcher-form" onsubmit="App.submitDispatcherForm(event); return false;">
                <div class="form-group">
                    <label>Employee Number *</label>
                    <input type="text" name="employee_number" required placeholder="e.g., 1001">
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" required placeholder="John">
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" required placeholder="Smith">
                    </div>
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label>Seniority Date *</label>
                        <input type="date" name="seniority_date" required>
                    </div>
                    <div class="form-group">
                        <label>Seniority Sequence *</label>
                        <input type="number" name="seniority_sequence" value="1" min="1" required>
                        <small>For same date, 1=most senior, 2=next, etc.</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Classification *</label>
                    <select name="classification" required>
                        <option value="extra_board">Extra Board</option>
                        <option value="job_holder">Job Holder</option>
                        <option value="qualifying">Qualifying</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Dispatcher</button>
                </div>
            </form>
        `;
        this.showModal('Create Dispatcher', html);
    },

    submitDispatcherForm: async function(event) {
        event.preventDefault();
        const form = event.target;
        const data = {
            employee_number: form.employee_number.value,
            first_name: form.first_name.value,
            last_name: form.last_name.value,
            seniority_date: form.seniority_date.value,
            seniority_sequence: parseInt(form.seniority_sequence.value),
            classification: form.classification.value
        };

        try {
            await this.api('dispatcher_create', data);
            this.showSuccess('Dispatcher created successfully');
            await this.loadDispatchers();
            this.closeModal();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to create dispatcher: ' + error.message);
        }
    },

    /**
     * Edit dispatcher
     */
    editDispatcher: function(id) {
        const dispatcher = this.data.dispatchers.find(d => d.id == id);
        if (!dispatcher) return;

        const html = `
            <form id="edit-dispatcher-form" onsubmit="App.submitEditDispatcherForm(event, ${id}); return false;">
                <div class="alert alert-info">
                    <strong>Dispatcher:</strong> ${dispatcher.employee_number} - ${dispatcher.first_name} ${dispatcher.last_name}<br>
                    <strong>Seniority Date:</strong> ${dispatcher.seniority_date}<br>
                    <strong>Seniority Rank:</strong> ${dispatcher.seniority_rank}
                </div>
                <div class="form-group">
                    <label>Classification *</label>
                    <select name="classification" required>
                        <option value="extra_board" ${dispatcher.classification === 'extra_board' ? 'selected' : ''}>Extra Board</option>
                        <option value="job_holder" ${dispatcher.classification === 'job_holder' ? 'selected' : ''}>Job Holder</option>
                        <option value="qualifying" ${dispatcher.classification === 'qualifying' ? 'selected' : ''}>Qualifying</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.manageQualifications(${id})">Manage Qualifications</button>
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        `;
        this.showModal('Edit Dispatcher', html);
    },

    submitEditDispatcherForm: async function(event, id) {
        event.preventDefault();
        const dispatcher = this.data.dispatchers.find(d => d.id == id);
        const form = event.target;

        try {
            await this.api('dispatcher_update', {
                id: id,
                employee_number: dispatcher.employee_number,
                first_name: dispatcher.first_name,
                last_name: dispatcher.last_name,
                seniority_date: dispatcher.seniority_date,
                classification: form.classification.value,
                active: true
            });
            this.showSuccess('Dispatcher updated successfully');
            await this.loadDispatchers();
            this.closeModal();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to update dispatcher: ' + error.message);
        }
    },

    /**
     * Manage dispatcher qualifications
     */
    manageQualifications: function(dispatcherId) {
        const dispatcher = this.data.dispatchers.find(d => d.id == dispatcherId);
        if (!dispatcher) return;

        // Group desks by division for better organization
        const desksByDivision = {};
        this.data.desks.forEach(desk => {
            if (!desksByDivision[desk.division_name]) {
                desksByDivision[desk.division_name] = [];
            }
            desksByDivision[desk.division_name].push(desk);
        });

        let html = `
            <form id="qualifications-form" onsubmit="App.submitQualificationsForm(event, ${dispatcherId}); return false;">
                <div class="alert alert-info">
                    <strong>Dispatcher:</strong> ${dispatcher.employee_number} - ${dispatcher.first_name} ${dispatcher.last_name}
                </div>
                <p><strong>Select all desks this dispatcher is qualified for:</strong></p>
                <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--border-color); padding: 15px; border-radius: 5px;">
        `;

        // Load current qualifications via API
        this.api('dispatcher_qualifications', { dispatcher_id: dispatcherId }).then(qualifications => {
            const qualifiedDeskIds = qualifications.filter(q => q.qualified).map(q => q.desk_id);

            Object.keys(desksByDivision).sort().forEach(divisionName => {
                html += `<div style="margin-bottom: 20px;">
                            <h4 style="color: var(--primary-color); margin-bottom: 10px;">${divisionName}</h4>`;

                desksByDivision[divisionName].forEach(desk => {
                    const isQualified = qualifiedDeskIds.includes(desk.id);
                    html += `
                        <div style="margin-left: 20px; margin-bottom: 5px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="desk_${desk.id}" value="1" ${isQualified ? 'checked' : ''}
                                       style="margin-right: 10px; width: 18px; height: 18px;">
                                <span>${desk.name} (${desk.code})</span>
                            </label>
                        </div>
                    `;
                });

                html += `</div>`;
            });

            html += `
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.editDispatcher(${dispatcherId})">Back</button>
                    <button type="submit" class="btn btn-primary">Save Qualifications</button>
                </div>
            </form>
            `;

            this.showModal('Manage Qualifications', html);
        });
    },

    submitQualificationsForm: async function(event, dispatcherId) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);

        try {
            // Get all desks and update qualifications
            const updates = [];
            for (const desk of this.data.desks) {
                const isQualified = formData.get(`desk_${desk.id}`) === '1';
                updates.push(
                    this.api('dispatcher_set_qualification', {
                        dispatcher_id: dispatcherId,
                        desk_id: desk.id,
                        qualified: isQualified,
                        qualified_date: isQualified ? new Date().toISOString().split('T')[0] : null
                    })
                );
            }

            await Promise.all(updates);
            this.showSuccess('Qualifications updated successfully');
            this.closeModal();
        } catch (error) {
            this.showError('Failed to update qualifications: ' + error.message);
        }
    },

    /**
     * Manage desk assignments
     */
    manageDeskAssignments: async function(deskId) {
        const desk = this.data.desks.find(d => d.id == deskId);
        if (!desk) return;

        // Load current assignments
        let currentAssignments = [];
        try {
            currentAssignments = await this.api('desk_get_assignments', { desk_id: deskId });
        } catch (error) {
            console.error('Failed to load current assignments:', error);
        }

        const dispatcherOptions = this.data.dispatchers.map(d =>
            `<option value="${d.id}">${d.employee_number} - ${d.first_name} ${d.last_name} (${this.formatClassification(d.classification)})</option>`
        ).join('');

        const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        let currentAssignmentsHtml = '';
        if (currentAssignments.length > 0) {
            currentAssignmentsHtml = `
                <div class="alert alert-success" style="margin-bottom: 20px;">
                    <strong>Current Assignments:</strong>
                    <table style="width: 100%; margin-top: 10px; font-size: 0.9em;">
                        <thead>
                            <tr>
                                <th style="text-align: left;">Shift</th>
                                <th style="text-align: left;">Dispatcher</th>
                                <th style="text-align: left;">Schedule</th>
                                <th style="text-align: left;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${currentAssignments.map(a => {
                                let scheduleDisplay = '';
                                let actionButton = '';

                                if (a.assignment_type === 'relief') {
                                    // Relief: show which shift on which days (formatted from schedule_summary)
                                    scheduleDisplay = a.schedule_summary || 'Pattern: 11 22 3';
                                    actionButton = `<button type="button" class="btn btn-sm btn-secondary" onclick="App.editReliefSchedule(${deskId}, ${a.dispatcher_id})">
                                        Edit Schedule
                                    </button>`;
                                } else {
                                    // Regular: show rest days
                                    scheduleDisplay = a.rest_days
                                        ? `<strong>Off:</strong> ${a.rest_days.split(',').map(d => daysOfWeek[parseInt(d)]).join(', ')}`
                                        : 'Standard weekend pattern';
                                    actionButton = `<button type="button" class="btn btn-sm btn-secondary" onclick="App.editRestDays(${a.assignment_id}, ${deskId}, '${a.shift}', ${a.dispatcher_id})">
                                        Edit Rest Days
                                    </button>`;
                                }

                                return `
                                    <tr>
                                        <td>${a.shift.charAt(0).toUpperCase() + a.shift.slice(1)}</td>
                                        <td>${a.employee_number} - ${a.first_name} ${a.last_name}</td>
                                        <td>${scheduleDisplay}</td>
                                        <td>${actionButton}</td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        const html = `
            ${currentAssignmentsHtml}
            <form id="assignment-form" onsubmit="App.submitAssignmentForm(event, ${deskId}); return false;">
                <div class="alert alert-info">
                    <strong>Desk:</strong> ${desk.name} (${desk.code})<br>
                    <strong>Division:</strong> ${desk.division_name}
                </div>
                <h3 style="margin-top: 20px;">Add New Assignment</h3>
                <div class="form-group">
                    <label>Assignment Type *</label>
                    <select name="assignment_type" required onchange="App.updateAssignmentForm(this)">
                        <option value="">Select type...</option>
                        <option value="first">First Shift (0600-1400)</option>
                        <option value="second">Second Shift (1400-2200)</option>
                        <option value="third">Third Shift (2200-0600)</option>
                        <option value="relief">Relief Dispatcher (Weekend Coverage)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Dispatcher *</label>
                    <select name="dispatcher_id" required>
                        <option value="">Select dispatcher...</option>
                        ${dispatcherOptions}
                    </select>
                </div>
                <div id="relief-info" class="alert alert-warning" style="display: none;">
                    <strong>Relief Dispatcher Coverage:</strong><br>
                    Relief dispatcher works ONE shift per day, rotating through shifts to provide rest days for regular shift holders.<br>
                    <strong>Standard "11 22 3" Pattern:</strong><br>
                    • <strong>Sun/Mon:</strong> First Shift (0600-1400)<br>
                    • <strong>Tue/Wed:</strong> Second Shift (1400-2200)<br>
                    • <strong>Thu:</strong> Third Shift (2200-0600)<br>
                    • <strong>Fri/Sat:</strong> Off<br>
                    <small>(You can customize the schedule after assignment)</small>
                </div>
                <div id="rest-days-option" style="margin-top: 15px; padding: 15px; background: var(--light-bg); border-radius: 5px;">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" name="configure_rest_days" value="1" style="margin-right: 10px; width: 18px; height: 18px;">
                        <span><strong>Configure custom rest days</strong> (e.g., Tuesday & Wednesday instead of weekends)</span>
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Assign</button>
                </div>
            </form>
        `;
        this.showModal('Assign Dispatcher', html);
    },

    updateAssignmentForm: function(select) {
        const reliefInfo = document.getElementById('relief-info');
        const restDaysOption = document.getElementById('rest-days-option');

        if (select.value === 'relief') {
            reliefInfo.style.display = 'block';
            if (restDaysOption) restDaysOption.style.display = 'none';
        } else {
            reliefInfo.style.display = 'none';
            if (restDaysOption) restDaysOption.style.display = 'block';
        }
    },

    submitAssignmentForm: async function(event, deskId) {
        event.preventDefault();
        const form = event.target;
        const assignmentType = form.assignment_type.value;
        const dispatcherId = form.dispatcher_id.value;
        const configureRestDays = form.configure_rest_days && form.configure_rest_days.checked;

        try {
            if (assignmentType === 'relief') {
                await this.api('schedule_generate_standard_relief', {
                    desk_id: deskId,
                    relief_dispatcher_id: dispatcherId
                });
                this.showSuccess('Relief dispatcher assigned with standard schedule');
                this.closeModal();
                this.showView(this.currentView);
            } else {
                // If user wants to configure rest days, show that modal
                if (configureRestDays) {
                    this.configureRestDays(deskId, assignmentType, dispatcherId);
                } else {
                    // Standard assignment without custom rest days
                    await this.api('schedule_assign_job', {
                        dispatcher_id: dispatcherId,
                        desk_id: deskId,
                        shift: assignmentType,
                        assignment_type: 'regular'
                    });
                    this.showSuccess(`${assignmentType.charAt(0).toUpperCase() + assignmentType.slice(1)} shift assigned successfully`);
                    this.closeModal();
                    this.showView(this.currentView);
                }
            }
        } catch (error) {
            this.showError('Failed to assign: ' + error.message);
        }
    },

    /**
     * Edit desk - Manage default rest days for each shift
     */
    editDesk: async function(id) {
        const desk = this.data.desks.find(d => d.id === id);
        if (!desk) return;

        // Load existing rest days
        try {
            const response = await fetch(`api/desk_rest_days.php?desk_id=${id}`, {
                headers: { 'Cache-Control': 'no-cache' }
            });
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error);
            }

            const restDays = result.rest_days;

            // Day names
            const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const shifts = [
                { id: 'first', name: 'First Shift' },
                { id: 'second', name: 'Second Shift' },
                { id: 'third', name: 'Third Shift' },
                { id: 'relief', name: 'Relief' }
            ];

            // Build checkboxes for each shift
            const shiftsHtml = shifts.map(shift => {
                const checkboxes = dayNames.map((day, index) => {
                    const checked = restDays[shift.id].includes(index) ? 'checked' : '';
                    return `
                        <label style="display: inline-block; margin-right: 15px; margin-bottom: 8px;">
                            <input type="checkbox" name="${shift.id}_restday" value="${index}" ${checked}>
                            ${day}
                        </label>
                    `;
                }).join('');

                return `
                    <div class="form-group">
                        <label style="font-weight: bold; display: block; margin-bottom: 8px;">${shift.name}:</label>
                        <div style="padding-left: 10px;">
                            ${checkboxes}
                        </div>
                    </div>
                `;
            }).join('');

            const html = `
                <form id="rest-days-form" onsubmit="App.saveRestDays(event, ${id})">
                    <div class="form-group">
                        <p><strong>Desk:</strong> ${desk.name} (${desk.code})</p>
                        <p style="color: #666; font-size: 14px;">
                            Select the standard rest days for each shift at this desk.
                            These will be applied when dispatchers are assigned to these positions.
                        </p>
                    </div>

                    ${shiftsHtml}

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Rest Days</button>
                        <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    </div>
                </form>
            `;

            this.showModal(`Manage Rest Days - ${desk.name}`, html);

        } catch (error) {
            this.showError('Failed to load rest days: ' + error.message);
        }
    },

    /**
     * Save rest days for a desk
     */
    saveRestDays: async function(event, deskId) {
        event.preventDefault();

        const form = event.target;
        const shifts = ['first', 'second', 'third', 'relief'];
        const restDays = {};

        // Collect checked days for each shift
        shifts.forEach(shift => {
            const checkboxes = form.querySelectorAll(`input[name="${shift}_restday"]:checked`);
            restDays[shift] = Array.from(checkboxes).map(cb => parseInt(cb.value));
        });

        try {
            const response = await fetch('api/desk_rest_days.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    desk_id: deskId,
                    rest_days: restDays
                })
            });

            const result = await response.json();

            if (result.success) {
                this.closeModal();
                this.showSuccess('Rest days saved successfully');
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            this.showError('Failed to save rest days: ' + error.message);
        }
    },

    /**
     * Show vacancy modal
     */
    showVacancyModal: function() {
        const deskOptions = this.data.desks.map(d =>
            `<option value="${d.id}">${d.division_name} - ${d.name}</option>`
        ).join('');

        const html = `
            <form id="vacancy-form" onsubmit="App.submitVacancyForm(event); return false;">
                <div class="form-group">
                    <label>Desk *</label>
                    <select name="desk_id" required>
                        <option value="">Select desk...</option>
                        ${deskOptions}
                    </select>
                </div>
                <div class="form-group">
                    <label>Shift *</label>
                    <select name="shift" required>
                        <option value="">Select shift...</option>
                        <option value="first">First Shift (0600-1400)</option>
                        <option value="second">Second Shift (1400-2200)</option>
                        <option value="third">Third Shift (2200-0600)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" name="vacancy_date" required>
                </div>
                <div class="form-group">
                    <label>Vacancy Type *</label>
                    <select name="vacancy_type" required>
                        <option value="sick">Sick</option>
                        <option value="vacation">Vacation</option>
                        <option value="training">Training</option>
                        <option value="loa">Leave of Absence</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Vacancy</button>
                </div>
            </form>
        `;
        this.showModal('Create Vacancy', html);
    },

    submitVacancyForm: async function(event) {
        event.preventDefault();
        const form = event.target;
        const vacancyType = form.vacancy_type.value;
        const data = {
            desk_id: form.desk_id.value,
            shift: form.shift.value,
            vacancy_date: form.vacancy_date.value,
            vacancy_type: vacancyType,
            is_planned: vacancyType !== 'sick' && vacancyType !== 'other'
        };

        try {
            await this.api('vacancy_create', data);
            this.showSuccess('Vacancy created successfully');
            this.closeModal();
            this.loadVacancies();
        } catch (error) {
            this.showError('Failed to create vacancy: ' + error.message);
        }
    },

    /**
     * Show holddown modal
     */
    showHolddownModal: function() {
        const deskOptions = this.data.desks.map(d =>
            `<option value="${d.id}">${d.division_name} - ${d.name}</option>`
        ).join('');

        const dispatcherOptions = this.data.dispatchers.map(d =>
            `<option value="${d.id}">${d.employee_number} - ${d.first_name} ${d.last_name}</option>`
        ).join('');

        const html = `
            <form id="holddown-form" onsubmit="App.submitHolddownForm(event); return false;">
                <div class="form-group">
                    <label>Desk *</label>
                    <select name="desk_id" required>
                        <option value="">Select desk...</option>
                        ${deskOptions}
                    </select>
                </div>
                <div class="form-group">
                    <label>Shift *</label>
                    <select name="shift" required>
                        <option value="">Select shift...</option>
                        <option value="first">First Shift (0600-1400)</option>
                        <option value="second">Second Shift (1400-2200)</option>
                        <option value="third">Third Shift (2200-0600)</option>
                    </select>
                </div>
                <div class="grid grid-2">
                    <div class="form-group">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label>End Date *</label>
                        <input type="date" name="end_date" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Incumbent Dispatcher *</label>
                    <select name="incumbent_dispatcher_id" required>
                        <option value="">Select incumbent...</option>
                        ${dispatcherOptions}
                    </select>
                    <small>The dispatcher going on vacation/training/leave</small>
                </div>
                <div class="alert alert-info">
                    This hold-down will be posted for bidding. Qualified dispatchers can submit bids, and it will be awarded to the most senior bidder.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Post Hold-Down</button>
                </div>
            </form>
        `;
        this.showModal('Post Hold-Down', html);
    },

    submitHolddownForm: async function(event) {
        event.preventDefault();
        const form = event.target;
        const data = {
            desk_id: form.desk_id.value,
            shift: form.shift.value,
            start_date: form.start_date.value,
            end_date: form.end_date.value,
            incumbent_dispatcher_id: form.incumbent_dispatcher_id.value
        };

        try {
            await this.api('holddown_post', data);
            this.showSuccess('Hold-down posted for bidding');
            this.closeModal();
            this.loadHolddowns();
        } catch (error) {
            this.showError('Failed to post hold-down: ' + error.message);
        }
    },

    /**
     * View bids for a holddown
     */
    viewBids: async function(id) {
        try {
            const bids = await this.api('holddown_bids', { holddown_id: id });
            if (bids.length === 0) {
                alert('No bids yet for this hold-down.');
                return;
            }

            let msg = 'Hold-down Bids (by seniority):\n\n';
            bids.forEach(bid => {
                msg += `${bid.seniority_rank}. ${bid.dispatcher_name} (${bid.employee_number})\n`;
                msg += `   Bid time: ${bid.bid_timestamp}\n\n`;
            });
            alert(msg);
        } catch (error) {
            this.showError('Failed to load bids: ' + error.message);
        }
    },
    awardHolddown: async function(id) {
        if (!confirm('Award this hold-down to the most senior bidder?')) return;
        try {
            await this.api('holddown_award', { holddown_id: id });
            this.showSuccess('Hold-down awarded successfully');
            this.loadHolddowns();
        } catch (error) {
            this.showError('Failed to award hold-down: ' + error.message);
        }
    },

    /**
     * Delete dispatcher
     */
    deleteDispatcher: async function(id, name) {
        if (!confirm(`Are you sure you want to delete dispatcher "${name}"?\n\nThis will set them as inactive.`)) return;

        try {
            const dispatcher = this.data.dispatchers.find(d => d.id == id);
            await this.api('dispatcher_update', {
                id: id,
                employee_number: dispatcher.employee_number,
                first_name: dispatcher.first_name,
                last_name: dispatcher.last_name,
                seniority_date: dispatcher.seniority_date,
                classification: dispatcher.classification,
                active: false
            });
            this.showSuccess('Dispatcher deleted successfully');
            await this.loadDispatchers();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to delete dispatcher: ' + error.message);
        }
    },

    /**
     * Delete desk
     */
    deleteDesk: async function(id, name) {
        if (!confirm(`Are you sure you want to delete desk "${name}"?\n\nThis will set it as inactive.`)) return;

        try {
            await this.api('desk_delete', { id: id });
            this.showSuccess('Desk deleted successfully');
            await this.loadDesks();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to delete desk: ' + error.message);
        }
    },

    /**
     * Delete division
     */
    deleteDivision: async function(id, name) {
        if (!confirm(`Are you sure you want to delete division "${name}"?\n\nThis will set it as inactive. All desks in this division will remain.`)) return;

        try {
            await this.api('division_delete', { id: id });
            this.showSuccess('Division deleted successfully');
            await this.loadDivisions();
            await this.loadDesks();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to delete division: ' + error.message);
        }
    },

    /**
     * Show manage divisions modal with list
     */
    showManageDivisionsModal: function() {
        let html = `
            <div style="margin-bottom: 20px;">
                <button class="btn btn-primary" onclick="App.showDivisionModal(); return false;">Add New Division</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
        `;

        if (this.data.divisions.length === 0) {
            html += '<tr><td colspan="3" class="text-center">No divisions found</td></tr>';
        } else {
            this.data.divisions.forEach(div => {
                html += `
                    <tr>
                        <td><strong>${div.code}</strong></td>
                        <td>${div.name}</td>
                        <td>
                            <button class="btn btn-danger" onclick="App.deleteDivision(${div.id}, '${div.name}')">Delete</button>
                        </td>
                    </tr>
                `;
            });
        }

        html += `
                </tbody>
            </table>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Close</button>
            </div>
        `;

        this.showModal('Manage Divisions', html);
    },

    /**
     * Configure rest days for a job assignment
     */
    configureRestDays: function(deskId, shift, dispatcherId) {
        const desk = this.data.desks.find(d => d.id == deskId);
        const dispatcher = this.data.dispatchers.find(d => d.id == dispatcherId);

        const daysOfWeek = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        const html = `
            <form id="rest-days-form" onsubmit="App.submitRestDaysForm(event, ${deskId}, '${shift}', ${dispatcherId}); return false;">
                <div class="alert alert-info">
                    <strong>Dispatcher:</strong> ${dispatcher.employee_number} - ${dispatcher.first_name} ${dispatcher.last_name}<br>
                    <strong>Desk:</strong> ${desk.name} (${desk.code})<br>
                    <strong>Shift:</strong> ${shift.charAt(0).toUpperCase() + shift.slice(1)}
                </div>
                <p><strong>Select rest days (days OFF) for this assignment:</strong></p>
                <p><small>In a typical 5-day work week, select 2 rest days. For example: Tuesday and Wednesday.</small></p>
                <div style="margin: 20px 0;">
                    ${daysOfWeek.map((day, index) => `
                        <div style="margin-bottom: 10px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="rest_day_${index}" value="1"
                                       style="margin-right: 10px; width: 18px; height: 18px;">
                                <span style="font-size: 1.1em;">${day}</span>
                            </label>
                        </div>
                    `).join('')}
                </div>
                <div class="alert alert-warning">
                    <strong>Note:</strong> Days NOT checked will be work days. Relief dispatcher coverage (if assigned) takes precedence over these settings.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Rest Days</button>
                </div>
            </form>
        `;

        this.showModal('Configure Rest Days', html);
    },

    submitRestDaysForm: async function(event, deskId, shift, dispatcherId) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);

        const restDays = [];
        for (let i = 0; i < 7; i++) {
            if (formData.get(`rest_day_${i}`) === '1') {
                restDays.push(i);
            }
        }

        try {
            // First create the job assignment
            const result = await this.api('schedule_assign_job', {
                dispatcher_id: dispatcherId,
                desk_id: deskId,
                shift: shift,
                assignment_type: 'regular'
            });

            // Then save rest days if any were selected
            if (restDays.length > 0) {
                await this.api('job_set_rest_days', {
                    job_assignment_id: result.id,
                    rest_days: restDays
                });
            }

            this.showSuccess(`${shift.charAt(0).toUpperCase() + shift.slice(1)} shift assigned with custom rest days`);
            this.closeModal();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to assign: ' + error.message);
        }
    },

    /**
     * Edit rest days for an existing assignment
     */
    editRestDays: async function(assignmentId, deskId, shift, dispatcherId) {
        const desk = this.data.desks.find(d => d.id == deskId);
        const dispatcher = this.data.dispatchers.find(d => d.id == dispatcherId);

        // Load current rest days
        let currentRestDays = [];
        try {
            const result = await this.api('job_get_rest_days', { job_assignment_id: assignmentId });
            currentRestDays = result.map(r => parseInt(r.day_of_week));
        } catch (error) {
            console.error('Failed to load rest days:', error);
        }

        const daysOfWeek = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        const html = `
            <form id="edit-rest-days-form" onsubmit="App.submitEditRestDaysForm(event, ${assignmentId}); return false;">
                <div class="alert alert-info">
                    <strong>Dispatcher:</strong> ${dispatcher.employee_number} - ${dispatcher.first_name} ${dispatcher.last_name}<br>
                    <strong>Desk:</strong> ${desk.name} (${desk.code})<br>
                    <strong>Shift:</strong> ${shift.charAt(0).toUpperCase() + shift.slice(1)}
                </div>
                <p><strong>Select rest days (days OFF) for this assignment:</strong></p>
                <p><small>Leave all unchecked to use standard weekend relief pattern.</small></p>
                <div style="margin: 20px 0;">
                    ${daysOfWeek.map((day, index) => `
                        <div style="margin-bottom: 10px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="rest_day_${index}" value="1"
                                    ${currentRestDays.includes(index) ? 'checked' : ''}
                                    style="margin-right: 10px; width: 18px; height: 18px;">
                                <span style="font-size: 1.1em;">${day}</span>
                            </label>
                        </div>
                    `).join('')}
                </div>
                <div class="alert alert-warning">
                    <strong>Note:</strong> Relief dispatcher coverage (if assigned) takes precedence over these custom rest days.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Rest Days</button>
                </div>
            </form>
        `;

        this.showModal('Edit Rest Days', html);
    },

    submitEditRestDaysForm: async function(event, assignmentId) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);

        const restDays = [];
        for (let i = 0; i < 7; i++) {
            if (formData.get(`rest_day_${i}`) === '1') {
                restDays.push(i);
            }
        }

        try {
            await this.api('job_set_rest_days', {
                job_assignment_id: assignmentId,
                rest_days: restDays
            });

            this.showSuccess('Rest days updated successfully');
            this.closeModal();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to update rest days: ' + error.message);
        }
    },

    /**
     * Edit relief dispatcher schedule (which shift on which days)
     */
    editReliefSchedule: async function(deskId, dispatcherId) {
        const desk = this.data.desks.find(d => d.id == deskId);
        const dispatcher = this.data.dispatchers.find(d => d.id == dispatcherId);

        // Load current relief schedule
        let currentSchedule = {};
        try {
            const result = await this.api('relief_get_schedule', { desk_id: deskId });
            // Build map of day -> shift
            result.forEach(entry => {
                const day = parseInt(entry.day_of_week);
                if (!currentSchedule[day]) {
                    currentSchedule[day] = [];
                }
                currentSchedule[day].push(entry.shift);
            });
        } catch (error) {
            console.error('Failed to load relief schedule:', error);
        }

        const daysOfWeek = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const shifts = [
            { id: 'first', label: '1st (0600-1400)' },
            { id: 'second', label: '2nd (1400-2200)' },
            { id: 'third', label: '3rd (2200-0600)' }
        ];

        const html = `
            <form id="edit-relief-schedule-form" onsubmit="App.submitEditReliefScheduleForm(event, ${deskId}, ${dispatcherId}); return false;">
                <div class="alert alert-info">
                    <strong>Relief Dispatcher:</strong> ${dispatcher.employee_number} - ${dispatcher.first_name} ${dispatcher.last_name}<br>
                    <strong>Desk:</strong> ${desk.name} (${desk.code})
                </div>
                <p><strong>Select which shift to work on each day:</strong></p>
                <p><small>Relief dispatcher works ONE shift per day (8 hours), rotating through shifts to cover rest days.</small></p>
                <div style="margin: 20px 0;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: var(--primary-color); color: white;">
                                <th style="padding: 8px; text-align: left;">Day</th>
                                <th style="padding: 8px; text-align: center;">1st Shift</th>
                                <th style="padding: 8px; text-align: center;">2nd Shift</th>
                                <th style="padding: 8px; text-align: center;">3rd Shift</th>
                                <th style="padding: 8px; text-align: center;">Off</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${daysOfWeek.map((day, dayIndex) => {
                                const hasFirst = currentSchedule[dayIndex]?.includes('first');
                                const hasSecond = currentSchedule[dayIndex]?.includes('second');
                                const hasThird = currentSchedule[dayIndex]?.includes('third');
                                const hasNone = !currentSchedule[dayIndex] || currentSchedule[dayIndex].length === 0;
                                return `
                                    <tr style="border-bottom: 1px solid #ddd;">
                                        <td style="padding: 8px;"><strong>${day}</strong></td>
                                        <td style="padding: 8px; text-align: center;">
                                            <input type="radio" name="day_${dayIndex}" value="first" ${hasFirst ? 'checked' : ''}>
                                        </td>
                                        <td style="padding: 8px; text-align: center;">
                                            <input type="radio" name="day_${dayIndex}" value="second" ${hasSecond ? 'checked' : ''}>
                                        </td>
                                        <td style="padding: 8px; text-align: center;">
                                            <input type="radio" name="day_${dayIndex}" value="third" ${hasThird ? 'checked' : ''}>
                                        </td>
                                        <td style="padding: 8px; text-align: center;">
                                            <input type="radio" name="day_${dayIndex}" value="off" ${hasNone ? 'checked' : ''}>
                                        </td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-warning">
                    <strong>Standard "11 22 3" Pattern:</strong> Sun/Mon: 1st, Tue/Wed: 2nd, Thu: 3rd, Fri/Sat: Off
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Schedule</button>
                </div>
            </form>
        `;

        this.showModal('Edit Relief Schedule', html);
    },

    submitEditReliefScheduleForm: async function(event, deskId, dispatcherId) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);

        // Build schedule array: [{day: 0, shift: 'first'}, {day: 1, shift: 'first'}, ...]
        const schedule = [];
        for (let day = 0; day < 7; day++) {
            const shift = formData.get(`day_${day}`);
            if (shift && shift !== 'off') {
                schedule.push({ day: day, shift: shift });
            }
        }

        if (schedule.length === 0) {
            this.showError('Please select at least one shift for relief coverage');
            return;
        }

        try {
            await this.api('relief_update_schedule', {
                desk_id: deskId,
                relief_dispatcher_id: dispatcherId,
                schedule: schedule
            });

            this.showSuccess('Relief schedule updated successfully');
            this.closeModal();
            this.showView(this.currentView);
        } catch (error) {
            this.showError('Failed to update relief schedule: ' + error.message);
        }
    },

    /**
     * Validate CSV file
     */
    validateCSV: async function() {
        const statusDiv = document.getElementById('import-status');
        const importBtn = document.getElementById('import-btn');

        statusDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Validating CSV...</p></div>';

        try {
            const response = await fetch('tools/import_web.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'validate' })
            });

            const result = await response.json();

            if (result.success) {
                const stats = result.stats;
                const divisionsHtml = Object.keys(stats.divisions).slice(0, 5).join(', ') +
                    (Object.keys(stats.divisions).length > 5 ? '...' : '');

                statusDiv.innerHTML = `
                    <div class="alert alert-success">
                        <strong>CSV Validation Successful!</strong><br>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>Total records: ${stats.total}</li>
                            <li>Active dispatchers: <strong>${stats.active}</strong></li>
                            <li>Inactive (will be skipped): ${stats.inactive}</li>
                            <li>Divisions: ${stats.division_count} (${divisionsHtml})</li>
                            <li>Unique desks: ${stats.desks}</li>
                        </ul>
                        <p><strong>Ready to import ${stats.active} dispatchers.</strong></p>
                    </div>
                `;
                importBtn.disabled = false;
            } else {
                statusDiv.innerHTML = `<div class="alert alert-danger">Validation failed: ${result.error}</div>`;
                importBtn.disabled = true;
            }
        } catch (error) {
            statusDiv.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            importBtn.disabled = true;
        }
    },

    /**
     * Import CSV data
     */
    importCSV: async function() {
        if (!confirm('This will import all dispatcher data from data.csv.\n\nIf you already have data, this may create duplicates.\n\nContinue?')) {
            return;
        }

        const statusDiv = document.getElementById('import-status');
        const importBtn = document.getElementById('import-btn');

        statusDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Importing data... This may take a few minutes.</p></div>';
        importBtn.disabled = true;

        try {
            const response = await fetch('tools/import_web.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'import' })
            });

            const result = await response.json();

            if (result.success) {
                statusDiv.innerHTML = `
                    <div class="alert alert-success">
                        <strong>Import Completed Successfully!</strong><br>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>Imported: <strong>${result.imported}</strong> dispatchers</li>
                            <li>Skipped: ${result.skipped} records</li>
                            <li>Created: ${result.divisions} divisions</li>
                            <li>Created: ${result.desks} desks</li>
                        </ul>
                        <p><strong>Refresh the page to see the imported data.</strong></p>
                    </div>
                `;

                // Reload data
                setTimeout(() => {
                    this.loadInitialData();
                    this.showView('dispatchers');
                }, 2000);
            } else {
                statusDiv.innerHTML = `<div class="alert alert-danger">Import failed: ${result.error}</div>`;
                importBtn.disabled = false;
            }
        } catch (error) {
            statusDiv.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            importBtn.disabled = false;
        }
    },

    /**
     * Clear all dispatcher data
     */
    clearAllData: async function() {
        if (!confirm('⚠️ WARNING: This will DELETE ALL DATA including:\n\n- All dispatchers\n- All divisions and desks\n- All assignments\n- All vacancies and hold-downs\n- All scheduling data\n\nThis CANNOT be undone!\n\nAre you absolutely sure?')) {
            return;
        }

        if (!confirm('Final confirmation: Delete ALL data?\n\nType your answer mentally: YES to proceed')) {
            return;
        }

        const statusDiv = document.getElementById('import-status');

        statusDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Clearing all data...</p></div>';

        try {
            const response = await fetch('tools/import_web.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clear_data' })
            });

            const result = await response.json();

            if (result.success) {
                statusDiv.innerHTML = `
                    <div class="alert alert-success">
                        <strong>All data cleared successfully!</strong><br>
                        <p>You can now import fresh data from the CSV file.</p>
                    </div>
                `;

                // Reload data
                setTimeout(() => {
                    this.loadInitialData();
                    this.showView('dispatchers');
                }, 1000);
            } else {
                statusDiv.innerHTML = `<div class="alert alert-danger">Failed to clear data: ${result.error}</div>`;
            }
        } catch (error) {
            statusDiv.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
        }
    }
};
