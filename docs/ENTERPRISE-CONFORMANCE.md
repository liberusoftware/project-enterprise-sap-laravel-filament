# Enterprise SAP conformance and migration

The Enterprise SAP Wayfinder uses [SAP.md](https://github.com/liberusoftware/documentation/blob/main/projects/sap/SAP.md) as the product standard. The [Wayfinder issue](https://github.com/liberusoftware/project-enterprise-sap-laravel-filament/issues/27) sequences the work across enterprise foundation, finance, commercial operations, delivery and governance.

## Current installation

Billing Core, Accounting Core, Chart of Accounts and General Ledger install from their published Composer packages. The admin wizard uses their actions to create the company legal entity, a primary accrual book and a billing account in one transaction. It records the owning team and the issued identifiers. The wizard also saves the company’s default locale and IANA time zone through Foundation Scoped Settings. The company owner can update them in the admin panel after setup. The Foundation audit recorder captures the actor, team, and before and after values. Team members can see the defaults and setup status in the app panel.

Billing Orders, Invoicing, Payments and Subscriptions are installed from published packages. **Finance → Billing receivables** lets a company owner record a finalized Billing invoice against an existing Accounting customer. The handoff takes the invoice and customer record IDs, checks both against the selected company, and records one open item per invoice. It currently supports company base currencies with up to two decimal places. The same page accepts a captured Billing allocation ID and uses Accounting to record and apply its receipt. Repeat submissions return the linked receipt. The published Accounting package can reject some exact final instalments due to floating-point balance calculations. The owning package has a local precision fix awaiting release. Refund reconciliation and gateway configuration remain separate workflow steps.

The Admin page also posts a reconciled Billing invoice to the company’s primary book through the Accounting General Ledger. Posting uses the invoice finalization date, requires an open Accounting Period, and checks the selected receivables control, revenue, and tax accounts against the company chart of accounts. The journal links back to the invoice and its Accounting receivable. Applied receipts can also be posted. The receipt journal debits a company cash or clearing account and credits the original invoice’s receivables account. It uses the receipt date for the posting period and retains links to the allocation and invoice journal.

The App panel’s **CRM deals** lookup reads deal names and stages from the existing CRM API. Configure `CRM_URL`, `CRM_DEAL_TOKEN`, `CRM_DEAL_TEAM_ID` for the Enterprise company, and `CRM_REMOTE_TEAM_ID` for the CRM team. The configured Enterprise team owner can use the lookup, and responses from other CRM teams are rejected. Embedded CRM Core still needs a published opt-in release for hosts that use its record schema without the CRM application’s legacy migrations.

The Admin panel’s **CMS site** view uses the existing CMS Core API to show site status, default locale, and channels. Configure `CMS_URL`, `CMS_SITE_TOKEN`, `CMS_SITE_TEAM_ID`, `CMS_SITE_ID`, and `CMS_SITE_KEY`. The configured company owner can refresh the view. Enterprise checks both the site key and ID and verifies the site ID on each returned channel. Embedded CMS Core and its presentation packages still need compatible module manifests. Billing and Accounting presentation packages need to pass the Enterprise panel's resource tenancy checks.

Ecommerce Contracts is installed. The App panel’s **Ecommerce orders** lookup reuses the existing Ecommerce order API and returns a limited order summary. Configure the HTTPS origin, the Enterprise team ID, and a dedicated Ecommerce account token in `ECOMMERCE_URL`, `ECOMMERCE_ORDER_TEAM_ID`, and `ECOMMERCE_ORDER_TOKEN`. Only the configured team’s owner can use the connection, and the Ecommerce API limits results to orders owned by the token holder. The App panel’s **Ecommerce catalogue** uses the same connection and the existing products endpoint for paginated storefront searches. Set `ECOMMERCE_STORE_ID` to the expected storefront ID and point `ECOMMERCE_URL` at its resolved storefront host. Results with other store IDs are rejected. Order synchronization remains open.

## Package ownership

| Domain | Source project | Enterprise composition boundary |
| --- | --- | --- |
| Billing | Billing Laravel | Billing owns subscription, usage, invoice, collection and payment state. Enterprise passes the company and source order references to billing. |
| CRM | CRM Laravel | CRM owns accounts, contacts, leads, opportunities and the sales lifecycle. Enterprise carries the accepted quote and customer references into ordering. |
| Content | CMS Laravel | CMS owns sites, channels, content versions and publication. Enterprise associates a company with its sites and channels. |
| Commerce | Ecommerce Laravel | Ecommerce owns products, checkout, orders and fulfilment. Its reusable contracts are packaged. Product and order implementations still live in that application and need a reusable package or a versioned service interface. |
| Accounting | Accounting ERP Laravel | Accounting owns legal entities, books, periods, journal entries and posting. Enterprise maps source documents to company and book, then reconciles the source with the posting result. |

## Domain inventory

| SAP.md domain | Current Enterprise state and reuse candidate |
| --- | --- |
| Enterprise Foundation | Organizations, teams, identity, roles, audit, files and localization are installed. Company setup links one team to a legal entity. Enterprise hierarchy, master data stewardship, delegations and document controls need workflow coverage. |
| Finance | Accounting Core, Chart of Accounts, General Ledger, Periods, and Accounts Receivable are installed. Enterprise exercises open-period invoice and receipt posting. Subledger reconciliation, revenue recognition and close controls remain separate delivery steps. |
| Controlling | Accounting ERP's project costing and management reporting are reuse candidates. Company-wide cost centers, allocations and planning need contract and ownership decisions. |
| Sales and CRM | CRM Core, sales pipelines and contracts are reuse candidates. The embedded core and the team-scoped presentation must pass migration and authorization tests before activation. |
| Revenue Operations | Billing Core, Orders, Subscriptions, Invoicing and Payments are installed. Finalized invoices and captured allocations hand off to Accounting. Provider configuration, refunds and automated source-to-posting reconciliation are still required. |
| Procurement | Accounting ERP provides purchase requisitions, orders and goods or service receipts. Enterprise must verify supplier identity, approval and matching before composing them. |
| Inventory and Logistics | Ecommerce handles order fulfilment and Accounting ERP provides inventory accounting. A company-scoped stock and fulfilment contract is still needed. |
| Projects and Services | Billing manages project and service delivery. Accounting ERP provides project costing and project profitability. Verify a shared project reference and milestone rules. |
| Service Management | CRM's service-agent, omnichannel service and field-service packages are candidates. Incident and change ownership still needs to be established. |
| Hosting and Cloud | Billing has hosting, usage and provisioning packages. Enterprise must verify provider credentials, scoped actions and retry evidence for the intended hosts. |
| Product and Engineering | Commerce and Billing own product and service records in their respective domains. Engineering release and roadmap ownership still needs assessment. |
| People | Teams and roles provide membership. Accounting ERP provides payroll integration. Workforce lifecycle and equipment ownership still need an identified source. |
| Assets and Facilities | Accounting ERP provides fixed assets and asset events. Maintenance, assignments and facilities workflows need owner and contract decisions. |
| Governance, Risk and Compliance | Audit and access controls are installed. Workflow-level approval evidence, legal holds and segregation of duties still need implementation and verification. |
| Partners and Portals | CRM provides partner relationship and self-service candidates. Billing and Accounting ERP provide customer portals. Enterprise needs delegated access rules and shared partner references. |
| Data and Intelligence | Foundation analytics and search are installed. Governed metrics, semantic reporting, operational-to-financial lineage and approval-gated automation still need delivery. |

## Migration order

1. Use the company setup’s explicit team-to-legal-entity mapping, base currency and regional defaults for the first integration. Existing setup records read the site locale (or the application fallback) and site time zone (UTC by default) until the owner saves company defaults. Store workflow timestamps in UTC and use the company’s time zone for display and future business-date decisions. The current posting flow uses the source invoice and receipt dates. Extend the enterprise hierarchy and legal-entity access rules before enabling intercompany operations. Scope regional tax and document numbering to the legal entity when those workflows are implemented.
2. Validate published manifests, migrations and permissions in Enterprise before connecting domain APIs or registering Filament resources. Reuse upstream resources wherever they can enforce the Enterprise panel's tenant boundaries. Put company administration in the admin panel and operational work in the app panel.
3. Connect lead to cash using accepted quote, order, invoice, payment and posting references. Assess the existing Business Workflow Reconciliation module's workflow run and case contracts for tenant-scoped idempotency, replay and outage reconciliation. Add Enterprise-owned handoffs only for the remaining integration gaps. Release this workflow with its record-to-report controls.
4. Apply the same ownership and reconciliation rules to source to pay, project to profit and incident to improvement. Verify module capabilities and versioned service contracts for each workflow before introducing a local integration.
5. Extend the company controls to hire to retire, asset lifecycle, partner access, hosting and infrastructure. Add consolidation, governance, reporting lineage and operating controls within each workflow that requires them.

## Workflow delivery gates

| Workflow | Acceptance evidence |
| --- | --- |
| Lead to cash | An approved and accepted CRM proposal version reaches the matching company order, invoice and ledger. Duplicate delivery produces one posting. |
| Source to pay | An approved purchase order, goods receipt and supplier bill reconcile. Unmatched and unapproved payments are held with an audit record. |
| Project to profit | Approved milestones, time and expenses reconcile to customer billing, project costing and margin. |
| Incident to improvement | Incident deadlines, escalation, approved change and recovery evidence link back to the affected service. |
| Hire to retire | Departures revoke company access and record equipment return and payroll handoff without retaining unnecessary personal data. |
| Record to report | Subledger balances and adjustments reconcile to a locked period. Consolidation traces intercompany eliminations to both source entities. |
| Asset lifecycle | Acquisition, book assignment, service history, depreciation and disposal reconcile to the ledger and preserve approval evidence. |

Each workflow needs a control matrix, tenant and role tests, audit evidence, a reconciliation report, recovery checks, telemetry and a runbook before release. The source applications own operational writes. Reports link back to the tenant, source record and relevant accounting period.

Enterprise coverage includes its host code and modules developed here. Reused packages run their source coverage suites in the owning repositories. Enterprise still tests fresh migrations, panel registration, company isolation and cross-module workflows with the published versions it installs.

## Standards and remaining decisions

| Standard | Enterprise implementation and remaining evidence |
| --- | --- |
| [Modules](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md) | Composer releases own installed modules and themes. The install workflow checks that a clean install reproduces the tracked package contents. Module validation still reports Laravel auto-discovery for Tenancy and Users Roles. Move those providers fully under module management with a fresh-boot and upgrade check. The current finance handoffs use package actions for writes and Eloquent model queries for source validation. Promote these lookups to package-owned queries when they become shared contracts. |
| [API](https://github.com/liberusoftware/documentation/blob/main/architecture/API.md) | The current CRM, CMS and Ecommerce connections use source application read APIs and the company owner’s configured connection. Lead-to-cash needs an approved quote contract, a versioned order handoff, a stable company and customer mapping, and repeatable reconciliation before enabling remote writes. |
| [Tenancy](https://github.com/liberusoftware/documentation/blob/main/architecture/TENANCY.md) | The admin uses the selected Filament team. The app uses the authenticated user’s accessible current team. The company mapping anchors legal-entity access. Delegated finance roles, cross-entity access and segregation of duties need policies before the workflow opens beyond company owners. |
| [Themes](https://github.com/liberusoftware/documentation/blob/main/standards/THEMES.md) | The existing panels, theme packages and Filament components deliver the company and finance views. Test any additional presentation package in both panel contexts before activation. |
| [Testing](https://github.com/liberusoftware/documentation/blob/main/standards/TESTING.md) and [CI](https://github.com/liberusoftware/documentation/blob/main/standards/CI.md) | Package owners test package internals. Enterprise verifies host composition, dependency resolution, tenant isolation, idempotency, and rollback. The CRM, CMS and Ecommerce tests use HTTP fixtures. Validate their deployment contracts and outage recovery against configured source environments before release. |

## Lead-to-cash replay dependency

Business Workflow Reconciliation 1.6.0 provides workflow runs, reconciliation cases and lifecycle transitions. Its run storage allows a nullable team and enforces correlation ID uniqueness across all teams. Starting a duplicate run raises a validation error. Cases have a tenant-scoped uniqueness constraint, while the create action directly inserts the case. Enterprise needs an atomic tenant-scoped start or resume operation, a recoverable record of completed steps and tests for concurrent delivery before installing the package for replay.

CRM Proposals and Quotes already owns proposal versions and approval and acceptance states. Its current HTTP API lists proposals by the authenticated CRM team, and its status-change event carries a local model. The CRM owner should expose a versioned accepted proposal reference and an immutable pricing snapshot. Billing Orders already converts an accepted Billing quote to a draft order and returns the existing order on retry. Enterprise should map the CRM proposal version to a company-scoped Billing quote through that contract and use the existing conversion action once the pricing and approval checks pass.

After the workflow package provides replay, Enterprise should persist references to the CRM proposal version, Billing quote, order and invoice, captured payment, and posted journals. Reconcile discrepancies through the package’s case lifecycle. Enable the handoff after tests show that duplicate or concurrent deliveries produce one effect, a failed step resumes without repeating earlier effects, and one company cannot view or replay another company’s run.

## Record-to-report reconciliation dependency

Accounts Receivable 1.0.0 provides a control-account reconciliation query. It sums open items across legal entities and can select the control account by code alone. The Accounting owner should extend the query to require a legal entity, book, control account and as-of date, with exact decimal totals. Verify the result against two companies that use the same account code and against receipts posted after the cutoff. Enterprise can then present its results for the company and period selected by an authorized user.
