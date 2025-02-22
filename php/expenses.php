<?php

include_once 'db_connection.php';

// Start the session
session_start();

// Access user_id from session variable
$user_id = $_SESSION['user_id'];


// Check if the 'logged_in' session variable exists and is set to true
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    // User is logged in, display the expenses page content
    // echo "Welcome to the expenses page!";
} else {
    // User is not logged in, redirect them to the login page
    header("Location: ../login.php");
    exit();
}

// Logout functionality
if (isset($_POST['logout'])) {
    // Unset all session variables
    $_SESSION = array();

    // Destroy the session
    session_destroy();

    // Redirect to login page after logout
    header("Location: ../login.php");
    exit();
}

// Database credentials
$hostname = "localhost"; // or your database host
$dbname = "mydata";
$username = "root";
$password = "";

// Attempt to establish a connection using mysqli
$mysqli = new mysqli($hostname, $username, $password, $dbname);

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Fetch budget settings for the user from the database
$userBudgetSettings = [];
$sqlBudget = "SELECT c.category_name, bs.budget_limit FROM budget_settings bs
              JOIN categories c ON bs.category_id = c.category_id
              WHERE bs.user_id = '$user_id'";
$resultBudget = $mysqli->query($sqlBudget);

if ($resultBudget->num_rows > 0) {
    while ($rowBudget = $resultBudget->fetch_assoc()) {
        $userBudgetSettings[$rowBudget['category_name']] = $rowBudget['budget_limit'];
    }
}

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve user input from form
    $expenseName = $_POST['expenseName'] ?? '';
    $expenseAmount = $_POST['expenseAmount'] ?? '';
    $category = $_POST['category'] ?? '';
    $date = $_POST['date'] ?? '';

    // Validate input (you can add more validation here)

    // Check if amount is not null
    if (!empty($expenseAmount)) {

        // Fetch budget limit for the specific timeframe (monthly, yearly, etc.)
        $timeframe = $_POST['timeframe'] ?? 'yearly';
        $budgetLimit = getBudgetLimitForTimeframe($conn, $user_id, $category, $timeframe);


        // Prepare and bind SQL statement
        $stmt = $conn->prepare("INSERT INTO expenses (user_id, amount, category, description, expense_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("idsss", $user_id, $expenseAmount, $category, $expenseName, $date);



        // Execute the statement
        if ($stmt->execute()) {
            

            // Check if the expense exceeds the budget limit for the category in the specific timeframe
            if ($budgetLimit !== null && $expenseAmount > $budgetLimit) {
                echo "Warning: Expense exceeds budget limit for category - " . $category . " in " . $timeframe;
            }

            // Subtract the expense amount from budget limit if budget limit exists
            if ($budgetLimit !== null) {
                $newBudgetLimit = $budgetLimit - $expenseAmount;
                updateBudgetLimitForTimeframe($conn, $user_id, $category, $timeframe, $newBudgetLimit);
            }


            // Example:
            // Retrieve the inserted expense_id
            $expense_id = $stmt->insert_id;

            // Get the year and month from the submitted expense data
            $year = date('Y', strtotime($date));
            $month = date('n', strtotime($date));

            // Check if the year exists in the expense_years table
            $stmt_year = $conn->prepare("SELECT year_id FROM expense_years WHERE user_id = ? AND year = ?");
            $stmt_year->bind_param("ii", $user_id, $year);
            $stmt_year->execute();
            $stmt_year->store_result();

            if ($stmt_year->num_rows == 0) {
                // Insert the year into the expense_years table if it does not exist
                $stmt_insert_year = $conn->prepare("INSERT INTO expense_years (user_id, year) VALUES (?, ?)");
                $stmt_insert_year->bind_param("ii", $user_id, $year);
                $stmt_insert_year->execute();
                $stmt_insert_year->close();
            }

            // Retrieve the year_id from the inserted or existing record in the expense_years table
            $stmt_year->bind_result($year_id);
            $stmt_year->fetch();
            $stmt_year->close();

            // Check if the month exists in the expense_months table
            $stmt_month = $conn->prepare("SELECT month_id FROM expense_months WHERE year_id = ? AND month = ?");
            $stmt_month->bind_param("ii", $year_id, $month);
            $stmt_month->execute();
            $stmt_month->store_result();

            // Retrieve the year_id from the expense_years table
            $stmt_year_id = $conn->prepare("SELECT year_id FROM expense_years WHERE user_id = ? AND year = ?");
            $stmt_year_id->bind_param("ii", $user_id, $year);
            $stmt_year_id->execute();
            $stmt_year_id->bind_result($year_id);
            $stmt_year_id->fetch();
            $stmt_year_id->close();

            if ($stmt_month->num_rows == 0) {
                // Insert the month into the expense_months table if it does not exist
                $stmt_insert_month = $conn->prepare("INSERT INTO expense_months (year_id, month) VALUES (?, ?)");
                $stmt_insert_month->bind_param("ii", $year_id, $month);
                $stmt_insert_month->execute();
                $stmt_insert_month->close();
            }

            // Retrieve the month_id from the inserted or existing record in the expense_months table
            // Prepare and execute a SELECT query to retrieve the month_id
            $stmt_select_month_id = $conn->prepare("SELECT month_id FROM expense_months WHERE year_id = ? AND month = ?");
            $stmt_select_month_id->bind_param("ii", $year_id, $month);
            $stmt_select_month_id->execute();
            $stmt_select_month_id->bind_result($month_id); // Bind a variable to store the result
            $stmt_select_month_id->fetch(); // Fetch the result
            $stmt_select_month_id->close(); // Close the statement

            // Now, $month_id contains the month_id for the given year_id and month


            // Insert the expense_month_details record
            $stmt_expense_month_details = $conn->prepare("INSERT INTO expense_month_details (month_id, expense_id) VALUES (?, ?)");
            $stmt_expense_month_details->bind_param("ii", $month_id, $expense_id);
            $stmt_expense_month_details->execute();
            $stmt_expense_month_details->close();

            $_SESSION['message'] = "Expense saved successfully.";
            header("Location: ../add.php"); // Redirect back to the form page
            exit();
           

        } else {
            $_SESSION['message'] = "Error: " . $conn->error;
            header("Location: ../add.php"); // Redirect back to the form page
        }

        // Close the statement
        $stmt->close();
    } else {
        $_SESSION['message'] = "Amount cannot be empty.";
        header("Location: ../add.php"); // Redirect back to the form page
        exit();
    }
}

// Function to fetch budget limit for a specific timeframe (e.g., monthly or yearly)
function getBudgetLimitForTimeframe($conn, $user_id, $category, $timeframe) {
    $budgetLimit = null;
    $sql = "SELECT budget_limit FROM budget_settings WHERE user_id = ? AND category_id = 
            (SELECT category_id FROM categories WHERE category_name = ?) AND timeframe = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $category, $timeframe);
    $stmt->execute();
    $stmt->bind_result($budgetLimit);
    
    if ($stmt->fetch()) {
        return $budgetLimit;
    } else {
        return null;
    }
}

function updateBudgetLimitForTimeframe($conn, $user_id, $category, $timeframe, $newBudgetLimit) {
    // Update the budget limit in the database for the specific user, category, and timeframe
    $sql = "UPDATE budget_settings SET budget_limit = ? WHERE user_id = ? AND category_id = 
            (SELECT category_id FROM categories WHERE category_name = ?) AND timeframe = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("diss", $newBudgetLimit, $user_id, $category, $timeframe);
    $stmt->execute();
}
// Close the connection
$conn->close();
?>


