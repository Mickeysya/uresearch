<div class="sidebar">
    <a href="home.php"><b>Dashboard</b></a>

    <?php if ($role == 'student'): ?>
        <a href="my_applications.php"><b>Track My Applications</b></a>
        <div class="section-label">Applications</div>
        <a href="travel_form.php" class="sub-link">New Travel Application</a>
        <a href="publication_form.php" class="sub-link">New Publication Application</a>
        <a href="claims_form.php" class="sub-link">New Claims Application</a>

    <?php elseif ($role == 'supervisor'): ?>
        <div class="section-label">Pending My Endorsement</div>
        <a href="travel_endorsement.php" class="sub-link">Travel</a>
        <a href="publication_endorsement.php" class="sub-link">Publication</a>
        <a href="claims_endorsement.php" class="sub-link">Claims</a>

    <?php elseif ($role == 'chair'): ?>
        <div class="section-label">Pending My Approval</div>
        <a href="travel_chair_approval.php" class="sub-link">Travel</a>
        <a href="publication_chair_approval.php" class="sub-link">Publication</a>
        <a href="claims_chair_approval.php" class="sub-link">Claims</a>

    <?php elseif ($role == 'non_exec_cgs'): ?>
        <div class="section-label">Pending My Review</div>
        <a href="travel_cgs_review.php" class="sub-link">Travel</a>
        <a href="publication_cgs_review.php" class="sub-link">Publication</a>
        <a href="claims_cgs_review.php" class="sub-link">Claims</a>

    <?php elseif ($role == 'dean_pgr'): ?>
        <div class="section-label">Final Approval</div>
        <a href="travel_dean_approval.php" class="sub-link">Travel</a>

    <?php elseif ($role == 'senior_director_cgs'): ?>
        <div class="section-label">Final Approval</div>
        <a href="publication_senior_director_approval.php" class="sub-link">Publication</a>

    <?php elseif ($role == 'manager_cgs'): ?>
        <div class="section-label">Final Approval</div>
        <a href="claims_manager_approval.php" class="sub-link">Claims</a>
    <?php endif; ?>

    <a href="logout.php" class="logout-link">Log out</a>
</div>
