# Task 2 -> Task 3 runtime sweep manifest

Exact mandatory files: **373**; matching lines: **1,633**.

These are the files the `NoTenantNoLegalEntityTest` scanner flags outside the migration allowlist after Task 2. Task 3 owns the runtime cleanup; this manifest is not an allowlist and must not be used to suppress hits.

## Console (2 files)

- `backend/app/Console/Commands/CapabilityAuditCommand.php`
- `backend/app/Console/Commands/OpsDemoStateCommand.php`

## Engagement (9 files)

- `backend/app/Modules/Engagement/Models/Notice.php`
- `backend/app/Modules/Engagement/Models/NoticeAcknowledgement.php`
- `backend/app/Modules/Engagement/Models/NoticeTemplate.php`
- `backend/app/Modules/Engagement/Models/Notification.php`
- `backend/app/Modules/Engagement/Services/EloquentNoticeWorkspaceDirectory.php`
- `backend/app/Modules/Engagement/Services/LogExternalNotificationChannel.php`
- `backend/app/Modules/Engagement/Services/NoticeGovernanceService.php`
- `backend/app/Modules/Engagement/Services/NoticeService.php`
- `backend/app/Modules/Engagement/Services/NotificationService.php`

## IAM (47 files)

- `backend/app/Modules/IAM/Auth/AuthenticatedPrincipal.php`
- `backend/app/Modules/IAM/Auth/CapabilityAuthorizer.php`
- `backend/app/Modules/IAM/Auth/PrincipalResolver.php`
- `backend/app/Modules/IAM/Auth/TimeActorBridge.php`
- `backend/app/Modules/IAM/Authorization/EloquentAccessSubjectStateDirectory.php`
- `backend/app/Modules/IAM/Authorization/EloquentAuthorityBindingRepository.php`
- `backend/app/Modules/IAM/Authorization/EloquentElevationGrantDirectory.php`
- `backend/app/Modules/IAM/Authorization/EloquentPrincipalAuthorityReader.php`
- `backend/app/Modules/IAM/Authorization/PermissionProjection.php`
- `backend/app/Modules/IAM/Authorization/PermissionResourceCatalog.php`
- `backend/app/Modules/IAM/Console/Commands/AccessReviewCommand.php`
- `backend/app/Modules/IAM/Console/Commands/BindRoleCommand.php`
- `backend/app/Modules/IAM/Console/Commands/InstallCommand.php`
- `backend/app/Modules/IAM/Console/Commands/SeedDemoCommand.php`
- `backend/app/Modules/IAM/Console/Commands/SyncCapabilitiesCommand.php`
- `backend/app/Modules/IAM/Http/Controllers/AccessReviewController.php`
- `backend/app/Modules/IAM/Http/Controllers/AuthController.php`
- `backend/app/Modules/IAM/Http/Controllers/AuthorizationAdminController.php`
- `backend/app/Modules/IAM/Http/Controllers/MeController.php`
- `backend/app/Modules/IAM/Http/Controllers/MfaController.php`
- `backend/app/Modules/IAM/Http/Controllers/UserAdminController.php`
- `backend/app/Modules/IAM/Models/AuthEvent.php`
- `backend/app/Modules/IAM/Models/AuthorityBinding.php`
- `backend/app/Modules/IAM/Models/BreakGlassGrant.php`
- `backend/app/Modules/IAM/Models/DataScopePolicy.php`
- `backend/app/Modules/IAM/Models/FieldPolicy.php`
- `backend/app/Modules/IAM/Models/LoginAttempt.php`
- `backend/app/Modules/IAM/Models/MfaFactor.php`
- `backend/app/Modules/IAM/Models/RecoveryCode.php`
- `backend/app/Modules/IAM/Models/Role.php`
- `backend/app/Modules/IAM/Models/RoleCapability.php`
- `backend/app/Modules/IAM/Models/Session.php`
- `backend/app/Modules/IAM/Models/User.php`
- `backend/app/Modules/IAM/Models/UserRole.php`
- `backend/app/Modules/IAM/Services/BreakGlassService.php`
- `backend/app/Modules/IAM/Services/DataScopePolicyService.php`
- `backend/app/Modules/IAM/Services/EloquentDemoUserDirectory.php`
- `backend/app/Modules/IAM/Services/EloquentUserAudienceDirectory.php`
- `backend/app/Modules/IAM/Services/EloquentUserDirectory.php`
- `backend/app/Modules/IAM/Services/EmploymentSessionRevoker.php`
- `backend/app/Modules/IAM/Services/FieldPolicyService.php`
- `backend/app/Modules/IAM/Services/IamAssigneeDirectory.php`
- `backend/app/Modules/IAM/Services/MfaPolicy.php`
- `backend/app/Modules/IAM/Services/MfaService.php`
- `backend/app/Modules/IAM/Services/RoleAdminService.php`
- `backend/app/Modules/IAM/Services/SecurityEventService.php`
- `backend/app/Modules/IAM/Services/SessionManager.php`

## Lifecycle (21 files)

- `backend/app/Modules/Lifecycle/Console/CheckLifecycleIntegrityCommand.php`
- `backend/app/Modules/Lifecycle/Console/LifecycleDemoCommand.php`
- `backend/app/Modules/Lifecycle/Console/SeedLifecycleTemplatesCommand.php`
- `backend/app/Modules/Lifecycle/Domain/LifecycleValidator.php`
- `backend/app/Modules/Lifecycle/EffectHandlers/OnboardingEffectHandler.php`
- `backend/app/Modules/Lifecycle/EffectHandlers/TermsChangeEffectHandler.php`
- `backend/app/Modules/Lifecycle/EffectHandlers/TransferEffectHandler.php`
- `backend/app/Modules/Lifecycle/Http/Controllers/LifecycleController.php`
- `backend/app/Modules/Lifecycle/Http/Controllers/SecondmentController.php`
- `backend/app/Modules/Lifecycle/Models/EmploymentContractVersion.php`
- `backend/app/Modules/Lifecycle/Models/LifecycleIntent.php`
- `backend/app/Modules/Lifecycle/Models/LifecycleTask.php`
- `backend/app/Modules/Lifecycle/Models/OnboardingChecklistTemplate.php`
- `backend/app/Modules/Lifecycle/Services/ChecklistService.php`
- `backend/app/Modules/Lifecycle/Services/EmploymentContractVersionLedgerService.php`
- `backend/app/Modules/Lifecycle/Services/LifecycleCaseListService.php`
- `backend/app/Modules/Lifecycle/Services/LifecycleEffectSupport.php`
- `backend/app/Modules/Lifecycle/Services/LifecycleProjectionService.php`
- `backend/app/Modules/Lifecycle/Services/LifecycleService.php`
- `backend/app/Modules/Lifecycle/Services/SecondmentService.php`
- `backend/app/Modules/Lifecycle/Services/TalentOnboardingDraftService.php`

## Organization (21 files)

- `backend/app/Modules/Organization/Authorization/EloquentOrganizationScopeDirectory.php`
- `backend/app/Modules/Organization/Commands/RebuildOrgClosureCommand.php`
- `backend/app/Modules/Organization/Contracts/DemoOrganizationSeeder.php`
- `backend/app/Modules/Organization/Contracts/OrganizationDirectory.php`
- `backend/app/Modules/Organization/Http/Controllers/DepartmentController.php`
- `backend/app/Modules/Organization/Http/Controllers/OrgUnitController.php`
- `backend/app/Modules/Organization/Http/Controllers/PositionCatalogController.php`
- `backend/app/Modules/Organization/Http/Controllers/PositionController.php`
- `backend/app/Modules/Organization/Models/LegalEntity.php`
- `backend/app/Modules/Organization/Models/OrgUnit.php`
- `backend/app/Modules/Organization/Models/OrgUnitClosure.php`
- `backend/app/Modules/Organization/Models/Position.php`
- `backend/app/Modules/Organization/Models/PositionGrade.php`
- `backend/app/Modules/Organization/Models/PositionQualification.php`
- `backend/app/Modules/Organization/Services/DemoOrganizationSeederService.php`
- `backend/app/Modules/Organization/Services/OrganizationBootstrapService.php`
- `backend/app/Modules/Organization/Services/OrganizationDirectoryService.php`
- `backend/app/Modules/Organization/Services/OrganizationFactsService.php`
- `backend/app/Modules/Organization/Services/OrgUnitService.php`
- `backend/app/Modules/Organization/Services/PositionCatalogService.php`
- `backend/app/Modules/Organization/Services/PositionService.php`

## Payroll (44 files)

- `backend/app/Modules/Payroll/Authorization/EloquentPayrollRunMembershipDirectory.php`
- `backend/app/Modules/Payroll/Console/PayrollReconciliationCommand.php`
- `backend/app/Modules/Payroll/Console/SeedDemoPayrollRunCommand.php`
- `backend/app/Modules/Payroll/Console/SeedPayrollParametersCommand.php`
- `backend/app/Modules/Payroll/Http/Controllers/BenefitController.php`
- `backend/app/Modules/Payroll/Http/Controllers/CompensationController.php`
- `backend/app/Modules/Payroll/Http/Controllers/PayrollCatalogController.php`
- `backend/app/Modules/Payroll/Http/Controllers/PayrollPayslipController.php`
- `backend/app/Modules/Payroll/Http/Controllers/PayrollRunController.php`
- `backend/app/Modules/Payroll/Http/Controllers/PayrollStatutoryParameterController.php`
- `backend/app/Modules/Payroll/Models/BenefitEnrollment.php`
- `backend/app/Modules/Payroll/Models/BenefitPlan.php`
- `backend/app/Modules/Payroll/Models/BenefitTransaction.php`
- `backend/app/Modules/Payroll/Models/Concerns/BelongsToTenant.php`
- `backend/app/Modules/Payroll/Models/EmployeeCompensation.php`
- `backend/app/Modules/Payroll/Models/PayrollAdjustment.php`
- `backend/app/Modules/Payroll/Models/PayrollInputSnapshot.php`
- `backend/app/Modules/Payroll/Models/PayrollItem.php`
- `backend/app/Modules/Payroll/Models/PayrollReceipt.php`
- `backend/app/Modules/Payroll/Models/PayrollRun.php`
- `backend/app/Modules/Payroll/Models/PayrollRunEmployee.php`
- `backend/app/Modules/Payroll/Models/PayrollScheme.php`
- `backend/app/Modules/Payroll/Models/PayrollSchemeItem.php`
- `backend/app/Modules/Payroll/Models/PayrollYtd.php`
- `backend/app/Modules/Payroll/Models/Payslip.php`
- `backend/app/Modules/Payroll/Models/PayslipLine.php`
- `backend/app/Modules/Payroll/Models/SalaryAdjustment.php`
- `backend/app/Modules/Payroll/Models/StatutoryParameter.php`
- `backend/app/Modules/Payroll/Models/TaxBracket.php`
- `backend/app/Modules/Payroll/Services/ApprovalService.php`
- `backend/app/Modules/Payroll/Services/BenefitService.php`
- `backend/app/Modules/Payroll/Services/CalculationService.php`
- `backend/app/Modules/Payroll/Services/CompensationService.php`
- `backend/app/Modules/Payroll/Services/CorrectionService.php`
- `backend/app/Modules/Payroll/Services/EloquentPayslipWorkspaceDirectory.php`
- `backend/app/Modules/Payroll/Services/ExportService.php`
- `backend/app/Modules/Payroll/Services/PayrollApprovalEffectService.php`
- `backend/app/Modules/Payroll/Services/PayrollRunService.php`
- `backend/app/Modules/Payroll/Services/PayrollStateMachine.php`
- `backend/app/Modules/Payroll/Services/PayrollYtdService.php`
- `backend/app/Modules/Payroll/Services/ReceiptReconciliationService.php`
- `backend/app/Modules/Payroll/Services/ReconciliationService.php`
- `backend/app/Modules/Payroll/Services/SnapshotService.php`
- `backend/app/Modules/Payroll/Services/TaxParameterResolver.php`

## People (46 files)

- `backend/app/Modules/People/Authorization/EloquentEmploymentManagerDirectory.php`
- `backend/app/Modules/People/Authorization/EloquentPersonEmploymentDirectory.php`
- `backend/app/Modules/People/Commands/PeopleAsOfCheckCommand.php`
- `backend/app/Modules/People/Commands/SeedPeopleDemoCommand.php`
- `backend/app/Modules/People/Http/Controllers/ContractLifecycleController.php`
- `backend/app/Modules/People/Http/Controllers/EmployeeController.php`
- `backend/app/Modules/People/Http/Controllers/EmployeeDocumentController.php`
- `backend/app/Modules/People/Http/Controllers/EmployeeProfileController.php`
- `backend/app/Modules/People/Http/Controllers/EmploymentContractController.php`
- `backend/app/Modules/People/Http/Controllers/EmploymentController.php`
- `backend/app/Modules/People/Models/Assignment.php`
- `backend/app/Modules/People/Models/ContractTemplate.php`
- `backend/app/Modules/People/Models/Document.php`
- `backend/app/Modules/People/Models/EmployeeCertificate.php`
- `backend/app/Modules/People/Models/EmployeeChangeRequest.php`
- `backend/app/Modules/People/Models/EmployeeContact.php`
- `backend/app/Modules/People/Models/EmployeeEducation.php`
- `backend/app/Modules/People/Models/EmployeeExperience.php`
- `backend/app/Modules/People/Models/EmployeeImportBatch.php`
- `backend/app/Modules/People/Models/Employment.php`
- `backend/app/Modules/People/Models/EmploymentContract.php`
- `backend/app/Modules/People/Models/EmploymentTerms.php`
- `backend/app/Modules/People/Models/EmploymentUserLink.php`
- `backend/app/Modules/People/Models/Person.php`
- `backend/app/Modules/People/Queries/AsOfEmployeeQuery.php`
- `backend/app/Modules/People/Services/AssignmentDirectoryService.php`
- `backend/app/Modules/People/Services/AssignmentService.php`
- `backend/app/Modules/People/Services/ContractLifecycleService.php`
- `backend/app/Modules/People/Services/DepartmentMembershipService.php`
- `backend/app/Modules/People/Services/DepartmentStaffingService.php`
- `backend/app/Modules/People/Services/DocumentService.php`
- `backend/app/Modules/People/Services/EloquentNoticeAudienceDirectory.php`
- `backend/app/Modules/People/Services/EloquentPeopleWorkspaceDirectory.php`
- `backend/app/Modules/People/Services/EloquentWorkforceIntegrityDirectory.php`
- `backend/app/Modules/People/Services/EloquentWorkforceReportingDirectory.php`
- `backend/app/Modules/People/Services/EmployeeProfileService.php`
- `backend/app/Modules/People/Services/EmploymentContractService.php`
- `backend/app/Modules/People/Services/EmploymentLifecycleDirectoryService.php`
- `backend/app/Modules/People/Services/EmploymentService.php`
- `backend/app/Modules/People/Services/OrgAssignmentImpactService.php`
- `backend/app/Modules/People/Services/PeopleDirectoryService.php`
- `backend/app/Modules/People/Services/PeopleEmploymentWriterService.php`
- `backend/app/Modules/People/Services/PersonPrivacy.php`
- `backend/app/Modules/People/Services/PersonService.php`
- `backend/app/Modules/People/Services/PositionOccupancyService.php`
- `backend/app/Modules/People/Services/TermsService.php`

## Platform (41 files)

- `backend/app/Modules/Platform/Contracts/ParameterReader.php`
- `backend/app/Modules/Platform/Demo/DemoDataset.php`
- `backend/app/Modules/Platform/Demo/Services/DemoSeeder.php`
- `backend/app/Modules/Platform/Demo/Services/DemoVerifier.php`
- `backend/app/Modules/Platform/Features/FeatureFlagService.php`
- `backend/app/Modules/Platform/Http/Controllers/DictionaryAdminController.php`
- `backend/app/Modules/Platform/Http/Controllers/NotificationAdminController.php`
- `backend/app/Modules/Platform/Logging/RequestLogMiddleware.php`
- `backend/app/Modules/Platform/Migration/Adapters/DatabaseMigrationTarget.php`
- `backend/app/Modules/Platform/Migration/Commands/MigrateRehearsalCommand.php`
- `backend/app/Modules/Platform/Migration/Importers/OrganizationCsvImporter.php`
- `backend/app/Modules/Platform/Migration/Importers/PeopleCsvImporter.php`
- `backend/app/Modules/Platform/Migration/Models/MigrationRun.php`
- `backend/app/Modules/Platform/Migration/Models/MigrationShadowRow.php`
- `backend/app/Modules/Platform/Migration/Models/StagingRow.php`
- `backend/app/Modules/Platform/Migration/Services/MigrationReconciler.php`
- `backend/app/Modules/Platform/Migration/Services/MigrationRollbackPlanner.php`
- `backend/app/Modules/Platform/Migration/Services/MigrationRunner.php`
- `backend/app/Modules/Platform/Migration/Services/ShadowMigrationTarget.php`
- `backend/app/Modules/Platform/Models/AuditEvent.php`
- `backend/app/Modules/Platform/Models/DictionaryEntry.php`
- `backend/app/Modules/Platform/Models/FileObject.php`
- `backend/app/Modules/Platform/Models/IdempotencyKey.php`
- `backend/app/Modules/Platform/Models/MenuOverride.php`
- `backend/app/Modules/Platform/Models/NotificationDelivery.php`
- `backend/app/Modules/Platform/Models/NotificationOutbox.php`
- `backend/app/Modules/Platform/Models/NotificationTemplate.php`
- `backend/app/Modules/Platform/Models/OutboxEvent.php`
- `backend/app/Modules/Platform/Models/SystemParameter.php`
- `backend/app/Modules/Platform/Retention/LegalHold.php`
- `backend/app/Modules/Platform/Retention/RetentionPurgeRun.php`
- `backend/app/Modules/Platform/Retention/RetentionService.php`
- `backend/app/Modules/Platform/Services/AuditService.php`
- `backend/app/Modules/Platform/Services/DatabaseDictionaryReader.php`
- `backend/app/Modules/Platform/Services/DatabaseParameterReader.php`
- `backend/app/Modules/Platform/Services/EloquentFileObjectReader.php`
- `backend/app/Modules/Platform/Services/FileMetadataAdapter.php`
- `backend/app/Modules/Platform/Services/IdempotencyService.php`
- `backend/app/Modules/Platform/Services/LocalPrivateFileStore.php`
- `backend/app/Modules/Platform/Services/MenuOverrideService.php`
- `backend/app/Modules/Platform/Services/OutboxService.php`

## Reporting (14 files)

- `backend/app/Modules/Reporting/Models/DashboardSnapshot.php`
- `backend/app/Modules/Reporting/Models/ExportJob.php`
- `backend/app/Modules/Reporting/Models/ProjectionRow.php`
- `backend/app/Modules/Reporting/Models/ReportDefinition.php`
- `backend/app/Modules/Reporting/Models/ReportRun.php`
- `backend/app/Modules/Reporting/Models/ReportSubscription.php`
- `backend/app/Modules/Reporting/Services/DashboardService.php`
- `backend/app/Modules/Reporting/Services/ExportJobRunner.php`
- `backend/app/Modules/Reporting/Services/ExportService.php`
- `backend/app/Modules/Reporting/Services/MetricQueryBuilder.php`
- `backend/app/Modules/Reporting/Services/ProjectionBuilder.php`
- `backend/app/Modules/Reporting/Services/ReportDefinitionCatalog.php`
- `backend/app/Modules/Reporting/Services/ReportService.php`
- `backend/app/Modules/Reporting/Services/ReportSubscriptionService.php`

## Shared (4 files)

- `backend/app/Shared/Authorization/AuthorizationSnapshot.php`
- `backend/app/Shared/Authorization/EnsureCapability.php`
- `backend/app/Shared/Authorization/ResolveLegalEntityContext.php`
- `backend/app/Shared/Authorization/ResolveResourceLegalEntityContext.php`

## Talent (42 files)

- `backend/app/Modules/Talent/Console/SeedTalentProgramsCommand.php`
- `backend/app/Modules/Talent/Contracts/OfferIntegrityReader.php`
- `backend/app/Modules/Talent/EffectHandlers/OfferApprovalEffectHandler.php`
- `backend/app/Modules/Talent/EffectHandlers/RequisitionApprovalEffectHandler.php`
- `backend/app/Modules/Talent/Http/Controllers/RecruitmentController.php`
- `backend/app/Modules/Talent/Http/Controllers/RecruitmentFunnelController.php`
- `backend/app/Modules/Talent/Http/Controllers/TrainingController.php`
- `backend/app/Modules/Talent/Models/Candidate.php`
- `backend/app/Modules/Talent/Models/CandidateCommunication.php`
- `backend/app/Modules/Talent/Models/CandidateConversion.php`
- `backend/app/Modules/Talent/Models/CandidateEvaluation.php`
- `backend/app/Modules/Talent/Models/CandidateImport.php`
- `backend/app/Modules/Talent/Models/Interview.php`
- `backend/app/Modules/Talent/Models/Offer.php`
- `backend/app/Modules/Talent/Models/PerformanceAppeal.php`
- `backend/app/Modules/Talent/Models/PerformanceCalibration.php`
- `backend/app/Modules/Talent/Models/PerformanceCycle.php`
- `backend/app/Modules/Talent/Models/PerformanceFeedback.php`
- `backend/app/Modules/Talent/Models/PerformanceGoal.php`
- `backend/app/Modules/Talent/Models/PerformanceIndicator.php`
- `backend/app/Modules/Talent/Models/PerformanceReview.php`
- `backend/app/Modules/Talent/Models/PerformanceTemplate.php`
- `backend/app/Modules/Talent/Models/Requisition.php`
- `backend/app/Modules/Talent/Models/RequisitionOpening.php`
- `backend/app/Modules/Talent/Models/TalentPoolTag.php`
- `backend/app/Modules/Talent/Models/TrainingCheckin.php`
- `backend/app/Modules/Talent/Models/TrainingCourse.php`
- `backend/app/Modules/Talent/Models/TrainingEnrollment.php`
- `backend/app/Modules/Talent/Models/TrainingExam.php`
- `backend/app/Modules/Talent/Models/TrainingFeedback.php`
- `backend/app/Modules/Talent/Models/TrainingPlan.php`
- `backend/app/Modules/Talent/Models/TrainingProgram.php`
- `backend/app/Modules/Talent/Models/TrainingSession.php`
- `backend/app/Modules/Talent/Services/OfferIntegrityReaderService.php`
- `backend/app/Modules/Talent/Services/PerformanceCycleService.php`
- `backend/app/Modules/Talent/Services/PerformanceService.php`
- `backend/app/Modules/Talent/Services/PerformanceTemplateService.php`
- `backend/app/Modules/Talent/Services/RecruitmentFunnelService.php`
- `backend/app/Modules/Talent/Services/RecruitmentService.php`
- `backend/app/Modules/Talent/Services/RequisitionOpeningService.php`
- `backend/app/Modules/Talent/Services/TrainingLifecycleService.php`
- `backend/app/Modules/Talent/Services/TrainingService.php`

## Time (50 files)

- `backend/app/Modules/Time/Console/LedgerReconcileCommand.php`
- `backend/app/Modules/Time/Console/SeedCalendarCommand.php`
- `backend/app/Modules/Time/Console/SeedLeaveTypesCommand.php`
- `backend/app/Modules/Time/Http/Controllers/AttendanceClosingController.php`
- `backend/app/Modules/Time/Http/Controllers/AttendanceController.php`
- `backend/app/Modules/Time/Http/Controllers/AttendanceOpsController.php`
- `backend/app/Modules/Time/Http/Controllers/CalendarAdminController.php`
- `backend/app/Modules/Time/Http/Controllers/LeaveController.php`
- `backend/app/Modules/Time/Http/Controllers/TimeCatalogController.php`
- `backend/app/Modules/Time/Http/Controllers/TravelPolicyController.php`
- `backend/app/Modules/Time/Models/AttendanceCorrection.php`
- `backend/app/Modules/Time/Models/AttendanceDay.php`
- `backend/app/Modules/Time/Models/AttendanceDevice.php`
- `backend/app/Modules/Time/Models/AttendancePeriodClosure.php`
- `backend/app/Modules/Time/Models/AttendanceRuleVersion.php`
- `backend/app/Modules/Time/Models/BusinessTripRecord.php`
- `backend/app/Modules/Time/Models/Holiday.php`
- `backend/app/Modules/Time/Models/LeaveAccount.php`
- `backend/app/Modules/Time/Models/LeaveBalance.php`
- `backend/app/Modules/Time/Models/LeaveLedgerEntry.php`
- `backend/app/Modules/Time/Models/LeaveRequestProjection.php`
- `backend/app/Modules/Time/Models/LeaveType.php`
- `backend/app/Modules/Time/Models/LegalEntityCalendar.php`
- `backend/app/Modules/Time/Models/OvertimeRecord.php`
- `backend/app/Modules/Time/Models/OvertimeRequest.php`
- `backend/app/Modules/Time/Models/OvertimeSettlement.php`
- `backend/app/Modules/Time/Models/PunchRecord.php`
- `backend/app/Modules/Time/Models/ShiftAssignment.php`
- `backend/app/Modules/Time/Models/ShiftSchedule.php`
- `backend/app/Modules/Time/Models/ShiftTemplate.php`
- `backend/app/Modules/Time/Models/TravelExpense.php`
- `backend/app/Modules/Time/Models/TravelPolicy.php`
- `backend/app/Modules/Time/Services/AttendanceCalculator.php`
- `backend/app/Modules/Time/Services/AttendanceClosingService.php`
- `backend/app/Modules/Time/Services/AttendanceCorrectionService.php`
- `backend/app/Modules/Time/Services/AttendanceOpsService.php`
- `backend/app/Modules/Time/Services/AttendanceWorkspaceReaderService.php`
- `backend/app/Modules/Time/Services/BusinessCalendar.php`
- `backend/app/Modules/Time/Services/EloquentLeaveLiabilityDirectory.php`
- `backend/app/Modules/Time/Services/LeaveAccountService.php`
- `backend/app/Modules/Time/Services/LeaveBalanceService.php`
- `backend/app/Modules/Time/Services/LeaveLedgerService.php`
- `backend/app/Modules/Time/Services/LeaveRequestService.php`
- `backend/app/Modules/Time/Services/OvertimeRequestService.php`
- `backend/app/Modules/Time/Services/OvertimeSettlementService.php`
- `backend/app/Modules/Time/Services/PayrollInputDirectoryService.php`
- `backend/app/Modules/Time/Services/PunchService.php`
- `backend/app/Modules/Time/Services/ShiftService.php`
- `backend/app/Modules/Time/Services/TravelRequestService.php`
- `backend/app/Modules/Time/Services/TravelService.php`

## Workflow (31 files)

- `backend/app/Modules/Workflow/Authorization/EloquentCaseMembershipDirectory.php`
- `backend/app/Modules/Workflow/Console/RunDueEffectsCommand.php`
- `backend/app/Modules/Workflow/Console/SeedWorkflowTemplatesCommand.php`
- `backend/app/Modules/Workflow/Http/Controllers/EffectController.php`
- `backend/app/Modules/Workflow/Http/Controllers/TaskController.php`
- `backend/app/Modules/Workflow/Http/Controllers/TemplateController.php`
- `backend/app/Modules/Workflow/Models/CaseEvent.php`
- `backend/app/Modules/Workflow/Models/CaseMember.php`
- `backend/app/Modules/Workflow/Models/CaseModel.php`
- `backend/app/Modules/Workflow/Models/Decision.php`
- `backend/app/Modules/Workflow/Models/Effect.php`
- `backend/app/Modules/Workflow/Models/WorkflowDelegation.php`
- `backend/app/Modules/Workflow/Models/WorkflowInstance.php`
- `backend/app/Modules/Workflow/Models/WorkflowTask.php`
- `backend/app/Modules/Workflow/Models/WorkflowTemplate.php`
- `backend/app/Modules/Workflow/Services/CaseCommandService.php`
- `backend/app/Modules/Workflow/Services/CaseQueryService.php`
- `backend/app/Modules/Workflow/Services/CaseService.php`
- `backend/app/Modules/Workflow/Services/DecisionService.php`
- `backend/app/Modules/Workflow/Services/DelegationService.php`
- `backend/app/Modules/Workflow/Services/DomainEffectHandlerAdapter.php`
- `backend/app/Modules/Workflow/Services/EffectExecutor.php`
- `backend/app/Modules/Workflow/Services/EffectProjectionService.php`
- `backend/app/Modules/Workflow/Services/EffectService.php`
- `backend/app/Modules/Workflow/Services/PayrollApprovalEffectHandler.php`
- `backend/app/Modules/Workflow/Services/PayrollApprovalWorkflowService.php`
- `backend/app/Modules/Workflow/Services/ReassignmentService.php`
- `backend/app/Modules/Workflow/Services/ReportingApprovalDirectoryService.php`
- `backend/app/Modules/Workflow/Services/TemplateService.php`
- `backend/app/Modules/Workflow/Services/WorkflowCaseGatewayService.php`
- `backend/app/Modules/Workflow/Services/WorkflowDelegationRevocationService.php`

## routes (1 files)

- `backend/routes/api.php`
