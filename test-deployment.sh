#!/bin/bash

# Test deployment script for College Feedback System

echo "Starting local deployment test..."

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "Error: Docker is not installed. Please install Docker first."
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo "Error: Docker Compose is not installed. Please install Docker Compose first."
    exit 1
fi

# Set environment variables for testing
export DB_USER=testuser
export DB_PASSWORD=testpassword
export MYSQL_ROOT_PASSWORD=rootpassword
export MYSQL_USER=testuser
export MYSQL_PASSWORD=testpassword
export MYSQL_DATABASE=college_feedback1

echo "Building and starting containers..."
docker-compose up -d --build

echo "Waiting for services to start..."
sleep 10

echo "Testing web service..."
curl -s http://localhost:8080/health-check.php

echo -e "\n\nTesting database connection..."
docker-compose exec db mysql -u$MYSQL_USER -p$MYSQL_PASSWORD -e "SHOW DATABASES;"

echo -e "\n\nDeployment test complete. To stop the services, run: docker-compose down" 