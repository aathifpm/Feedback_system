# College Feedback System

A web application for managing college feedback and surveys.

## Deployment on Render

This application is configured for deployment on Render using Docker with a PHP web service and MySQL database.

### Prerequisites

1. A [Render account](https://render.com/)
2. Your code pushed to a Git repository (GitHub, GitLab, etc.)

### Deployment Steps

1. **Connect your repository to Render**:
   - Log in to your Render account
   - Go to the Dashboard and click "New" > "Blueprint"
   - Connect your Git repository
   - Select the repository containing this application

2. **Configure Environment Variables**:
   During the deployment process, you'll need to set the following environment variables:
   - `DB_USER`: Database username
   - `DB_PASSWORD`: Database password
   - `MYSQL_ROOT_PASSWORD`: MySQL root password
   - `MYSQL_USER`: MySQL user (can be the same as DB_USER)
   - `MYSQL_PASSWORD`: MySQL password (can be the same as DB_PASSWORD)

3. **Deploy the Blueprint**:
   - Render will automatically detect the `render.yaml` file and configure the services
   - Review the configuration and click "Apply"
   - Render will create both the web service and the database service

4. **Access Your Application**:
   - Once deployment is complete, you can access your application at the URL provided by Render
   - The format will be: `https://college-feedback-app.onrender.com`

### Local Development with Docker

To run the application locally using Docker:

```bash
# Build and start the containers
docker-compose up -d

# Access the application
# Open http://localhost:8080 in your browser
```

### Database Initialization

The database will be automatically initialized with the schema defined in:
- `migration_script.sql`
- `alumni_survey_tables.sql`

These files are mounted as initialization scripts when the MySQL container starts.

## Troubleshooting

- **Database Connection Issues**: Verify that the environment variables are correctly set in Render
- **Application Errors**: Check the logs in the Render dashboard for both services
- **Database Initialization Failures**: Ensure your SQL scripts are compatible with MySQL 8.0 