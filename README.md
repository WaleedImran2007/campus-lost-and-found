# Campus Lost & Found System

A web-based campus lost and found management system built with PHP, MySQL, HTML, CSS and JavaScript.

The system allows students to report lost and found items, submit claims, communicate with other users and track item recovery. Custodians can review claims and manage handovers, while administrators manage users, categories, reports and system activity.

## Features

- User registration and authentication
- Role-based access control (Student, Custodian and Admin)
- Lost and found item listings
- Item images and private claim evidence uploads
- Claim submission, review and withdrawal
- Private messaging between users
- Notifications and reporting
- Custodian handover management
- Administrative dashboard and moderation
- Audit logs and rate limiting
- CSRF protection and input validation

## Requirements

- PHP 8.x
- MySQL 8.0.16+ or MariaDB 10.2.1+
- Apache 2.4+
- PHP extensions:
  - PDO MySQL
  - mbstring

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/WaleedImran2007/campus-lost-and-found.git
cd campus-lost-and-found
