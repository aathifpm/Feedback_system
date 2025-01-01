// Modal handling functions
function showAddModal() {
    const modal = document.getElementById('addModal');
    modal.style.display = 'flex';
    // Reset form when opening
    document.querySelector('#addModal form').reset();
}

function showEditModal(subject) {
    const modal = document.getElementById('editModal');
    
    // Set all subject info
    document.getElementById('edit_id').value = subject.id;
    document.getElementById('edit_code').value = subject.code;
    document.getElementById('edit_name').value = subject.name;
    document.getElementById('edit_department_id').value = subject.department_id;
    document.getElementById('edit_faculty_id').value = subject.faculty_id;
    document.getElementById('edit_academic_year_id').value = subject.academic_year_id;
    document.getElementById('edit_year').value = subject.year;
    document.getElementById('edit_semester').value = subject.semester;
    document.getElementById('edit_section').value = subject.section;
    document.getElementById('edit_credits').value = subject.credits;
    
    // Fetch and display current assignments
    fetchCurrentAssignments(subject.code);
    
    modal.style.display = 'flex';
}

function hideModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.style.display = 'none';
        });
    }
});

// Form validation functions
function validateForm(form) {
    let isValid = true;
    const requiredFields = form.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            isValid = false;
            field.classList.add('error');
        } else {
            field.classList.remove('error');
        }
    });

    if (!isValid) {
        alert('Please fill in all required fields');
        return false;
    }

    // Validate subject code format
    const codeField = form.querySelector('[name="code"]');
    if (codeField && !codeField.readOnly) {
        const codePattern = /^\d{2}[A-Z]{2,4}\d{4}$/;
        if (!codePattern.test(codeField.value)) {
            alert('Invalid subject code format. Please use format like 21AD1501 (YY + DEPT + NUMBER)');
            codeField.classList.add('error');
            return false;
        }
        codeField.classList.add('valid');
    }

    // Validate assignments
    return validateAssignments(form);
}

function validateAssignments(form) {
    const assignments = form.querySelectorAll('.assignment-row');
    if (assignments.length === 0) {
        alert('Please add at least one subject assignment');
        return false;
    }

    let isValid = true;
    assignments.forEach(assignment => {
        const selects = assignment.querySelectorAll('select');
        selects.forEach(select => {
            if (!select.value) {
                isValid = false;
                select.classList.add('error');
            } else {
                select.classList.remove('error');
            }
        });

        // Validate year and semester combination
        const year = parseInt(assignment.querySelector('[name="years[]"]').value);
        const semester = parseInt(assignment.querySelector('[name="semesters[]"]').value);
        if (semester > year * 2) {
            isValid = false;
            alert('Invalid year and semester combination');
            assignment.classList.add('error');
        } else {
            assignment.classList.remove('error');
        }
    });

    if (!isValid) {
        alert('Please check all assignment fields');
        return false;
    }

    return true;
}

// Assignment management functions
function addAssignment() {
    const template = document.querySelector('.assignment-row').cloneNode(true);
    // Reset values in the cloned template
    template.querySelectorAll('select').forEach(select => select.value = '');
    document.querySelector('.assignments').appendChild(template);
}

function removeAssignment(button) {
    const assignments = document.querySelector('.assignments');
    if (assignments.children.length > 1) {
        button.closest('.assignment-row').remove();
    }
}

function addNewAssignment() {
    const template = document.querySelector('.assignment-row').cloneNode(true);
    template.querySelectorAll('select').forEach(select => select.value = '');
    document.querySelector('.assignments').appendChild(template);
}

function toggleAssignmentStatus(id, status) {
    if (confirm('Are you sure you want to ' + (status ? 'activate' : 'deactivate') + ' this assignment?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="toggle_assignment_status">
            <input type="hidden" name="assignment_id" value="${id}">
            <input type="hidden" name="status" value="${status}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function fetchCurrentAssignments(subjectCode) {
    fetch(`get_subject_assignments.php?code=${subjectCode}`)
        .then(response => response.json())
        .then(assignments => {
            displayCurrentAssignments(assignments);
        })
        .catch(error => console.error('Error:', error));
}

function displayCurrentAssignments(assignments) {
    const container = document.getElementById('currentAssignmentsList');
    container.innerHTML = '';
    
    assignments.forEach(assignment => {
        const assignmentDiv = document.createElement('div');
        assignmentDiv.className = 'current-assignment-item';
        assignmentDiv.innerHTML = `
            <div class="assignment-details">
                <span>Year ${assignment.year} - Semester ${assignment.semester}</span>
                <span>Section ${assignment.section}</span>
                <span>Faculty: ${assignment.faculty_name}</span>
                <span class="status-badge ${assignment.is_active ? 'status-active' : 'status-inactive'}">
                    ${assignment.is_active ? 'Active' : 'Inactive'}
                </span>
            </div>
            <div class="assignment-actions">
                <button type="button" class="btn-action" 
                        onclick="toggleAssignmentStatus(${assignment.id}, ${!assignment.is_active})">
                    <i class="fas fa-power-off"></i>
                    ${assignment.is_active ? 'Deactivate' : 'Activate'}
                </button>
            </div>
        `;
        container.appendChild(assignmentDiv);
    });
}

// Filter functionality
document.addEventListener('DOMContentLoaded', function() {
    const subjectCards = document.querySelectorAll('.subject-card');
    const searchInput = document.getElementById('searchInput');
    const departmentFilter = document.getElementById('departmentFilter');
    const facultyFilter = document.getElementById('facultyFilter');
    const yearFilter = document.getElementById('yearFilter');
    const semesterFilter = document.getElementById('semesterFilter');
    const sectionFilter = document.getElementById('sectionFilter');
    const statusFilter = document.getElementById('statusFilter');

    function filterSubjects() {
        const searchTerm = searchInput.value.toLowerCase();
        const selectedDept = departmentFilter.value;
        const selectedFaculty = facultyFilter.value;
        const selectedYear = yearFilter.value;
        const selectedSemester = semesterFilter.value;
        const selectedSection = sectionFilter.value;
        const selectedStatus = statusFilter.value;

        subjectCards.forEach(card => {
            const name = card.querySelector('.subject-name').textContent.toLowerCase();
            const code = card.querySelector('.subject-code').textContent.toLowerCase();
            const department = card.dataset.department;
            const faculty = card.dataset.faculty;
            const year = card.dataset.year;
            const semester = card.dataset.semester;
            const section = card.dataset.section;
            const status = card.dataset.status;

            let showCard = true;

            if (searchTerm && !name.includes(searchTerm) && !code.includes(searchTerm)) {
                showCard = false;
            }
            if (selectedDept && department !== selectedDept) showCard = false;
            if (selectedFaculty && faculty !== selectedFaculty) showCard = false;
            if (selectedYear && year && year !== selectedYear) showCard = false;
            if (selectedSemester && semester && semester !== selectedSemester) showCard = false;
            if (selectedSection && section && section !== selectedSection) showCard = false;
            if (selectedStatus && status !== selectedStatus) showCard = false;

            card.classList.toggle('hidden', !showCard);
        });
    }

    // Add event listeners
    [searchInput, departmentFilter, facultyFilter, yearFilter, 
     semesterFilter, sectionFilter, statusFilter].forEach(element => {
        element.addEventListener('change', filterSubjects);
    });
    searchInput.addEventListener('input', filterSubjects);

    // Add form submission handlers
    document.querySelector('#addModal form').addEventListener('submit', function(e) {
        if (!validateForm(this)) {
            e.preventDefault();
        }
    });

    document.querySelector('#editModal form').addEventListener('submit', function(e) {
        if (!validateForm(this)) {
            e.preventDefault();
        }
    });

    // Add code input formatting
    const codeInputs = document.querySelectorAll('input[name="code"]');
    codeInputs.forEach(input => {
        if (!input.readOnly) {
            input.addEventListener('input', function() {
                formatSubjectCode(this);
            });
            
            input.addEventListener('blur', function() {
                if (this.value.length > 0 && !/^\d{2}[A-Z]{2,4}\d{4}$/.test(this.value)) {
                    this.classList.add('error');
                }
            });
        }
    });
});

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('departmentFilter').value = '';
    document.getElementById('facultyFilter').value = '';
    document.getElementById('yearFilter').value = '';
    document.getElementById('semesterFilter').value = '';
    document.getElementById('sectionFilter').value = '';
    document.getElementById('statusFilter').value = '';
    document.querySelectorAll('.subject-card').forEach(card => {
        card.classList.remove('hidden');
    });
}

function toggleStatus(id, status) {
    if (confirm('Are you sure you want to ' + (status ? 'activate' : 'deactivate') + ' this subject?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="id" value="${id}">
            <input type="hidden" name="status" value="${status}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewFeedback(id) {
    window.location.href = `view_subject_feedback.php?subject_id=${id}`;
}

function formatSubjectCode(input) {
    // Remove any non-alphanumeric characters
    let value = input.value.replace(/[^A-Z0-9]/gi, '');
    
    // Convert to uppercase
    value = value.toUpperCase();
    
    // Apply the format
    if (value.length >= 2) {
        // First 2 digits (year)
        let formatted = value.substr(0, 2);
        
        if (value.length >= 4) {
            // Department code (2-4 letters)
            const deptCode = value.substr(2).match(/[A-Z]+/)?.[0] || '';
            formatted += deptCode.substr(0, 4);
            
            // Remaining digits
            const remainingDigits = value.substr(2 + deptCode.length).match(/\d+/)?.[0] || '';
            if (remainingDigits) {
                formatted += remainingDigits.substr(0, 4);
            }
        } else {
            formatted += value.substr(2);
        }
        
        input.value = formatted;
    } else {
        input.value = value;
    }
} 