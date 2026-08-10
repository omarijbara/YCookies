# YCookies Documentation

---

- [Introduction](#introduction)
- [Getting Started](#getting-started)
- [Architecture](#architecture)

<a name="introduction"></a>
## Introduction

Welcome to the **YCookies internal documentation portal**. Here you will find all the technical specs, operational playbooks, and structural information required to maintain and develop the YCookies platform.

<a name="getting-started"></a>
## Getting Started

This portal is automatically rendered from markdown files located in your application's `resources/docs/1.0` folder. 
You can edit the `index.md` file to change the sidebar structure, and create new markdown files as your project scales.

<a name="architecture"></a>
## Architecture

The YCookies system is divided into two primary environments:
- **Control-Plane**: Laravel 12 API / Admin Dashboard (where you currently are).
- **Data-Plane**: Node.js low-latency proxy serving the scripts and analyzing traffic at the edge.

> **Note:** For operational logs or metrics, refer to the other modules within the Filament Admin sidebar.