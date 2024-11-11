<?php
class ExitSurveyValidator {
    private $errors = [];
    private $data = [];

    public function __construct($post_data) {
        $this->data = $post_data;
    }

    public function validate() {
        $this->validateBasicInfo();
        $this->validateRatings();
        $this->validateEmploymentDetails();
        return empty($this->errors);
    }

    public function getErrors() {
        return $this->errors;
    }

    private function validateBasicInfo() {
        // Name validation
        if (empty($this->data['name'])) {
            $this->errors['name'] = "Name is required";
        } elseif (!preg_match("/^[a-zA-Z ]{2,50}$/", $this->data['name'])) {
            $this->errors['name'] = "Name should contain only letters and spaces (2-50 characters)";
        }

        // Roll Number validation
        if (empty($this->data['roll_number'])) {
            $this->errors['roll_number'] = "Roll number is required";
        } elseif (!preg_match("/^[0-9A-Z]{5,15}$/", $this->data['roll_number'])) {
            $this->errors['roll_number'] = "Invalid roll number format";
        }

        // Register Number validation
        if (empty($this->data['register_number'])) {
            $this->errors['register_number'] = "Register number is required";
        } elseif (!preg_match("/^[0-9A-Z]{5,15}$/", $this->data['register_number'])) {
            $this->errors['register_number'] = "Invalid register number format";
        }

        // Year validation
        $current_year = date('Y');
        if (empty($this->data['passing_year'])) {
            $this->errors['passing_year'] = "Passing year is required";
        } elseif ($this->data['passing_year'] < 2000 || $this->data['passing_year'] > ($current_year + 4)) {
            $this->errors['passing_year'] = "Invalid passing year";
        }

        // Email validation
        if (empty($this->data['email'])) {
            $this->errors['email'] = "Email is required";
        } elseif (!filter_var($this->data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = "Invalid email format";
        }

        // Contact number validation
        if (empty($this->data['contact_number'])) {
            $this->errors['contact_number'] = "Contact number is required";
        } elseif (!preg_match("/^[0-9]{10}$/", $this->data['contact_number'])) {
            $this->errors['contact_number'] = "Invalid contact number format (10 digits required)";
        }

        // Address validation
        if (empty($this->data['contact_address'])) {
            $this->errors['contact_address'] = "Contact address is required";
        } elseif (strlen($this->data['contact_address']) < 10) {
            $this->errors['contact_address'] = "Address is too short";
        }
    }

    private function validateRatings() {
        // PO Ratings validation
        if (!isset($this->data['po_ratings']) || !is_array($this->data['po_ratings'])) {
            $this->errors['po_ratings'] = "Program Outcomes ratings are required";
        } else {
            foreach ($this->data['po_ratings'] as $index => $rating) {
                if (!in_array($rating, ['1', '2', '3', '4', '5'])) {
                    $this->errors['po_ratings_' . $index] = "Invalid rating value";
                }
            }
        }

        // PSO Ratings validation
        if (!isset($this->data['pso_ratings']) || !is_array($this->data['pso_ratings'])) {
            $this->errors['pso_ratings'] = "Program Specific Outcomes ratings are required";
        } else {
            foreach ($this->data['pso_ratings'] as $index => $rating) {
                if (!in_array($rating, ['1', '2', '3', '4', '5'])) {
                    $this->errors['pso_ratings_' . $index] = "Invalid rating value";
                }
            }
        }
    }

    private function validateEmploymentDetails() {
        if (empty($this->data['employment']['status'])) {
            $this->errors['employment_status'] = "Employment status is required";
        }

        if ($this->data['employment']['status'] === 'employed') {
            if (empty($this->data['employment']['employer_details'])) {
                $this->errors['employer_details'] = "Employer details are required";
            }

            if (isset($this->data['employment']['starting_salary']) && 
                !is_numeric($this->data['employment']['starting_salary'])) {
                $this->errors['starting_salary'] = "Invalid salary value";
            }

            if (isset($this->data['employment']['job_offers']) && 
                (!is_numeric($this->data['employment']['job_offers']) || 
                $this->data['employment']['job_offers'] < 0)) {
                $this->errors['job_offers'] = "Invalid number of job offers";
            }

            if (isset($this->data['employment']['interviews']) && 
                (!is_numeric($this->data['employment']['interviews']) || 
                $this->data['employment']['interviews'] < 0)) {
                $this->errors['interviews'] = "Invalid number of interviews";
            }
        }
    }
}
?> 