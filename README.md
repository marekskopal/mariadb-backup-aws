# MariaDbBackup to AWS S3

MariaDbBackup is a PHP tool for creating and uploading MariaDB backups to AWS S3.

Docker image is available at [Docker Hub](https://hub.docker.com/r/marekskopal/mariadb-backup-aws).

## Installation & Usage

### a) via Composer:

#### 1. Install the package via Composer:

```sh
composer require marekskopal/mariadb-backup
```

#### 2. Run backup script:

```sh
./vendor/marekskopal/mariadb-backup-aws/bin/console mariaDbBackup:aws /
  --host=your_db_host --user=your_db_user --password=your_db_password --database=your_db_name /
  --awsAccessKey=your_aws_key --awsSecretAccessKey=your_aws_secret --awsRegion=your_aws_region --awsBucket=your_aws_bucket
```


### b) via Docker Compose:

Add environment variables to your `.env` file:

```env
DB_HOST=your_db_host
DB_USER=your_db_user
DB_PASSWORD=your_db_password
DB_DATABASE=your_db_name

AWS_ACCESS_KEY=your_aws_key
AWS_SECRET_ACCESS_KEY=your_aws_secret
AWS_REGION=your_aws_region
AWS_BUCKET=your_aws_bucket
AWS_ROOT_PATH=your_aws_root_path
AWS_MAX_BACKUPS=10
```


Add to your `docker-compose.yml` file:

```yml
services:
    mariadb-backup-aws:
        image: marekskopal/mariadb-backup-aws:latest
        environment:
            DB_HOST: ${DB_HOST}
            DB_DATABASE: ${DB_DATABASE}
            DB_USER: ${DB_USER}
            DB_PASSWORD: ${DB_PASSWORD}
            AWS_ACCESS_KEY: ${AWS_ACCESS_KEY}
            AWS_SECRET_ACCESS_KEY: ${AWS_SECRET_ACCESS_KEY}
            AWS_REGION: ${AWS_REGION}
            AWS_BUCKET: ${AWS_BUCKET}
            AWS_ROOT_PATH: ${AWS_ROOT_PATH:-backup}
            AWS_MAX_BACKUPS: ${AWS_MAX_BACKUPS:-30}
        restart: unless-stopped
```

Cron in docker runs every day at 1:00 AM. or you can run backup manually:

```sh
docker-compose exec mariadb-backup-aws bin/console mariaDbBackup:aws
```
