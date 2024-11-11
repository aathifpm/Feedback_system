<div class="faculty-card">
    <div class="faculty-header">
        <h3><?php echo htmlspecialchars($faculty['name']); ?></h3>
        <p class="faculty-id">Faculty ID: <?php echo htmlspecialchars($faculty['faculty_id']); ?></p>
        <div class="faculty-meta">
            <p class="designation">
                <i class="fas fa-user-tag"></i> 
                <?php echo htmlspecialchars($faculty['designation']); ?>
            </p>
            <p class="department">
                <i class="fas fa-building"></i>
                <?php echo htmlspecialchars($faculty['department_name']); ?>
            </p>
        </div>
    </div>

    <div class="faculty-details">
        <div class="detail-item">
            <i class="fas fa-graduation-cap"></i>
            <span class="detail-label">Qualification:</span>
            <span class="detail-value"><?php echo htmlspecialchars($faculty['qualification']); ?></span>
        </div>
        <div class="detail-item">
            <i class="fas fa-briefcase"></i>
            <span class="detail-label">Experience:</span>
            <span class="detail-value"><?php echo htmlspecialchars($faculty['experience']); ?> years</span>
        </div>
        <div class="detail-item">
            <i class="fas fa-book"></i>
            <span class="detail-label">Specialization:</span>
            <span class="detail-value"><?php echo htmlspecialchars($faculty['specialization']); ?></span>
        </div>
    </div>

    <div class="feedback-stats">
        <div class="stat-group">
            <div class="stat-item">
                <i class="fas fa-book"></i>
                <span class="stat-value"><?php echo $faculty['total_subjects']; ?></span>
                <span class="stat-label">Subjects</span>
            </div>
            <div class="stat-item">
                <i class="fas fa-comments"></i>
                <span class="stat-value"><?php echo $faculty['total_feedback']; ?></span>
                <span class="stat-label">Feedbacks</span>
            </div>
            <div class="stat-item">
                <i class="fas fa-star"></i>
                <span class="stat-value"><?php echo $faculty['overall_avg']; ?></span>
                <span class="stat-label">Overall Rating</span>
            </div>
        </div>
    </div>

    <div class="rating-categories">
        <div class="rating-item">
            <div class="rating-label">Course Effectiveness</div>
            <div class="rating-bar">
                <div class="rating-fill" style="width: <?php echo ($faculty['course_effectiveness'] * 20); ?>%">
                    <?php echo $faculty['course_effectiveness']; ?>
                </div>
            </div>
        </div>
        <div class="rating-item">
            <div class="rating-label">Teaching Effectiveness</div>
            <div class="rating-bar">
                <div class="rating-fill" style="width: <?php echo ($faculty['teaching_effectiveness'] * 20); ?>%">
                    <?php echo $faculty['teaching_effectiveness']; ?>
                </div>
            </div>
        </div>
        <div class="rating-item">
            <div class="rating-label">Resources & Administration</div>
            <div class="rating-bar">
                <div class="rating-fill" style="width: <?php echo ($faculty['resources_admin'] * 20); ?>%">
                    <?php echo $faculty['resources_admin']; ?>
                </div>
            </div>
        </div>
        <div class="rating-item">
            <div class="rating-label">Assessment & Learning</div>
            <div class="rating-bar">
                <div class="rating-fill" style="width: <?php echo ($faculty['assessment_learning'] * 20); ?>%">
                    <?php echo $faculty['assessment_learning']; ?>
                </div>
            </div>
        </div>
        <div class="rating-item">
            <div class="rating-label">Course Outcomes</div>
            <div class="rating-bar">
                <div class="rating-fill" style="width: <?php echo ($faculty['course_outcomes'] * 20); ?>%">
                    <?php echo $faculty['course_outcomes']; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="rating-range">
        <span class="range-item">
            <i class="fas fa-arrow-down"></i>
            Min: <?php echo $faculty['min_rating']; ?>
        </span>
        <span class="range-item">
            <i class="fas fa-arrow-up"></i>
            Max: <?php echo $faculty['max_rating']; ?>
        </span>
    </div>

    <div class="faculty-actions">
        <a href="view_faculty_feedback.php?faculty_id=<?php echo $faculty['id']; ?>" 
           class="btn btn-primary">
            <i class="fas fa-chart-line"></i> View Detailed Analysis
        </a>
        <a href="generate_report.php?faculty_id=<?php echo $faculty['id']; ?>" 
           class="btn btn-secondary">
            <i class="fas fa-file-pdf"></i> Generate Report
        </a>
    </div>
</div>

<style>
.faculty-card {
    background: var(--bg-color);
    padding: 2rem;
    border-radius: 15px;
    box-shadow: var(--shadow);
    margin-bottom: 1.5rem;
    transition: transform 0.3s ease;
}

.faculty-card:hover {
    transform: translateY(-5px);
}

.faculty-header {
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(0,0,0,0.1);
}

.faculty-header h3 {
    font-size: 1.5rem;
    color: var(--text-color);
    margin-bottom: 0.5rem;
}

.faculty-id {
    color: #666;
    font-size: 0.9rem;
    margin-bottom: 0.5rem;
}

.faculty-meta {
    display: flex;
    gap: 1.5rem;
    margin-top: 0.5rem;
}

.faculty-meta p {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #666;
}

.faculty-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 0.8rem;
}

.detail-label {
    color: #666;
    font-weight: 500;
}

.detail-value {
    color: var(--text-color);
}

.feedback-stats {
    margin-bottom: 2rem;
}

.stat-group {
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 1.5rem;
}

.stat-item {
    text-align: center;
    padding: 1rem;
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
    min-width: 120px;
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 600;
    color: var(--primary-color);
    display: block;
    margin: 0.5rem 0;
}

.stat-label {
    color: #666;
    font-size: 0.9rem;
}

.faculty-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1.5rem;
}

@media (max-width: 768px) {
    .faculty-meta {
        flex-direction: column;
        gap: 0.5rem;
    }

    .faculty-details {
        grid-template-columns: 1fr;
    }

    .stat-group {
        flex-direction: column;
        align-items: center;
    }

    .stat-item {
        width: 100%;
        max-width: 200px;
    }

    .faculty-actions {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        text-align: center;
    }
}
</style>