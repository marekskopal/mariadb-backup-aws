# MariaDbBackup to AWS S3

MariaDbBackup is a PHP tool for creating and uploading MariaDB backups to AWS S3.


## Installation

### Install via Composer:

```sh
composer require marekskopal/mariadb-backup
```

### Install via Docker Compose:

```sh
docker-compose up -d
```


## Usage

### Environment Variables

Set the following environment variables in your `.env` file:

```env
DB_HOST=your_db_host
DB_USER=your_db_user
DB_PASSWORD=your_db_password
DB_DATABASE=your_db_name

AWS_ACCESS_KEY=your_aws_key
AWS_SECRET_ACCESS_KEY=your_aws_secret
AWS_REGION=your_aws_region
AWS_BUCKET=your_aws_bucket
