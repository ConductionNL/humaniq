<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Kernel\Contracts\AccessRevocationServiceInterface;
use OCA\Humaniq\Kernel\Contracts\AdministrationAccessTrustInterface;
use OCA\Humaniq\Kernel\Contracts\AgendaComposerInterface;
use OCA\Humaniq\Kernel\Contracts\AgendaReadAccessInterface;
use OCA\Humaniq\Kernel\Contracts\ApprovalsInboxServiceInterface;
use OCA\Humaniq\Kernel\Contracts\AssetDialectMigrationServiceInterface;
use OCA\Humaniq\Kernel\Contracts\AvailabilityServiceInterface;
use OCA\Humaniq\Kernel\Contracts\AvgDsrServiceInterface;
use OCA\Humaniq\Kernel\Contracts\CalendarSubscriptionPollerInterface;
use OCA\Humaniq\Kernel\Contracts\ChangeRequestApplierInterface;
use OCA\Humaniq\Kernel\Contracts\ChangeRequestServiceInterface;
use OCA\Humaniq\Kernel\Contracts\CompAdjustmentServiceInterface;
use OCA\Humaniq\Kernel\Contracts\CompCollectiveServiceInterface;
use OCA\Humaniq\Kernel\Contracts\CompCycleApproverInterface;
use OCA\Humaniq\Kernel\Contracts\DepartmentFiguresServiceInterface;
use OCA\Humaniq\Kernel\Contracts\DepartmentLeaveScheduleServiceInterface;
use OCA\Humaniq\Kernel\Contracts\EmploymentTermsResolverInterface;
use OCA\Humaniq\Kernel\Contracts\ExitInterviewServiceInterface;
use OCA\Humaniq\Kernel\Contracts\FormationOccupancyServiceInterface;
use OCA\Humaniq\Kernel\Contracts\HireMatchServiceInterface;
use OCA\Humaniq\Kernel\Contracts\HireServiceInterface;
use OCA\Humaniq\Kernel\Contracts\HrDocumentServiceInterface;
use OCA\Humaniq\Kernel\Contracts\HrDocumentSigningServiceInterface;
use OCA\Humaniq\Kernel\Contracts\InterviewCalendarServiceInterface;
use OCA\Humaniq\Kernel\Contracts\LearningPeopleFeedInterface;
use OCA\Humaniq\Kernel\Contracts\LeaveBalanceProjectionServiceInterface;
use OCA\Humaniq\Kernel\Contracts\LeaveBuySellSettlementServiceInterface;
use OCA\Humaniq\Kernel\Contracts\LeaveCalendarServiceInterface;
use OCA\Humaniq\Kernel\Contracts\LeaveCostServiceInterface;
use OCA\Humaniq\Kernel\Contracts\LeaveCoverageWarningInterface;
use OCA\Humaniq\Kernel\Contracts\LoonrunFlowRepairServiceInterface;
use OCA\Humaniq\Kernel\Contracts\ManagerAccessStampsInterface;
use OCA\Humaniq\Kernel\Contracts\ManagerStampRepairInterface;
use OCA\Humaniq\Kernel\Contracts\ObjectWeekHoursInterface;
use OCA\Humaniq\Kernel\Contracts\ObligationsServiceInterface;
use OCA\Humaniq\Kernel\Contracts\OfferEsignServiceInterface;
use OCA\Humaniq\Kernel\Contracts\OnCallAverageServiceInterface;
use OCA\Humaniq\Kernel\Contracts\OrgChartServiceInterface;
use OCA\Humaniq\Kernel\Contracts\PayrollGLPostServiceInterface;
use OCA\Humaniq\Kernel\Contracts\PayrollMutationServiceInterface;
use OCA\Humaniq\Kernel\Contracts\PayrollNetPayServiceInterface;
use OCA\Humaniq\Kernel\Contracts\PayrollReproduceServiceInterface;
use OCA\Humaniq\Kernel\Contracts\PayrollRunCheckServiceInterface;
use OCA\Humaniq\Kernel\Contracts\PayrollRunServiceInterface;
use OCA\Humaniq\Kernel\Contracts\PayTransparencyServiceInterface;
use OCA\Humaniq\Kernel\Contracts\PersonnelBudgetServiceInterface;
use OCA\Humaniq\Kernel\Contracts\ProformaPayslipServiceInterface;
use OCA\Humaniq\Kernel\Contracts\ReceiptExtractionServiceInterface;
use OCA\Humaniq\Kernel\Contracts\ReferralServiceInterface;
use OCA\Humaniq\Kernel\Contracts\RegisterImporterInterface;
use OCA\Humaniq\Kernel\Contracts\RegisterPrefillServiceInterface;
use OCA\Humaniq\Kernel\Contracts\ResourceBookingServiceInterface;
use OCA\Humaniq\Kernel\Contracts\RetentionExpiryServiceInterface;
use OCA\Humaniq\Kernel\Contracts\RetroAdjustmentServiceInterface;
use OCA\Humaniq\Kernel\Contracts\RuleAuditServiceInterface;
use OCA\Humaniq\Kernel\Contracts\RuleTestDataSeederInterface;
use OCA\Humaniq\Kernel\Contracts\SalaryBureauExchangeServiceInterface;
use OCA\Humaniq\Kernel\Contracts\SurveyServiceInterface;
use OCA\Humaniq\Kernel\Contracts\TeamDigestComposerInterface;
use OCA\Humaniq\Kernel\Contracts\TimeEstimateServiceInterface;
use OCA\Humaniq\Kernel\Contracts\TimesheetAggregationServiceInterface;
use OCA\Humaniq\Kernel\Contracts\TrainingCompetenceWriterInterface;
use OCA\Humaniq\Kernel\Contracts\VacancyMatchServiceInterface;
use OCA\Humaniq\Kernel\Contracts\WageTaxRemittanceServiceInterface;
use OCA\Humaniq\Kernel\Contracts\WkrServiceInterface;
use OCA\Humaniq\Kernel\Contracts\WorkingCalendarReaderInterface;
use OCA\Humaniq\Kernel\Contracts\WorkingHoursServiceInterface;
use OCA\Humaniq\Kernel\Contracts\WpmReportServiceInterface;
use OCA\Humaniq\Kernel\Contracts\YearTransitionServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Humaniq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
final class ContractBindings {

	/**
	 * Alias every Kernel contract to its implementation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AccessRevocationServiceInterface::class, AccessRevocationService::class);
		$context->registerServiceAlias(AdministrationAccessTrustInterface::class, AdministrationAccessTrust::class);
		$context->registerServiceAlias(AgendaComposerInterface::class, AgendaComposer::class);
		$context->registerServiceAlias(AgendaReadAccessInterface::class, AgendaReadAccess::class);
		$context->registerServiceAlias(ApprovalsInboxServiceInterface::class, ApprovalsInboxService::class);
		$context->registerServiceAlias(AssetDialectMigrationServiceInterface::class, AssetDialectMigrationService::class);
		$context->registerServiceAlias(AvailabilityServiceInterface::class, AvailabilityService::class);
		$context->registerServiceAlias(AvgDsrServiceInterface::class, AvgDsrService::class);
		$context->registerServiceAlias(CalendarSubscriptionPollerInterface::class, CalendarSubscriptionPoller::class);
		$context->registerServiceAlias(ChangeRequestApplierInterface::class, ChangeRequestApplier::class);
		$context->registerServiceAlias(ChangeRequestServiceInterface::class, ChangeRequestService::class);
		$context->registerServiceAlias(CompAdjustmentServiceInterface::class, CompAdjustmentService::class);
		$context->registerServiceAlias(CompCollectiveServiceInterface::class, CompCollectiveService::class);
		$context->registerServiceAlias(CompCycleApproverInterface::class, CompCycleApprover::class);
		$context->registerServiceAlias(DepartmentFiguresServiceInterface::class, DepartmentFiguresService::class);
		$context->registerServiceAlias(DepartmentLeaveScheduleServiceInterface::class, DepartmentLeaveScheduleService::class);
		$context->registerServiceAlias(EmploymentTermsResolverInterface::class, EmploymentTermsResolver::class);
		$context->registerServiceAlias(ExitInterviewServiceInterface::class, ExitInterviewService::class);
		$context->registerServiceAlias(FormationOccupancyServiceInterface::class, FormationOccupancyService::class);
		$context->registerServiceAlias(HireMatchServiceInterface::class, HireMatchService::class);
		$context->registerServiceAlias(HireServiceInterface::class, HireService::class);
		$context->registerServiceAlias(HrDocumentServiceInterface::class, HrDocumentService::class);
		$context->registerServiceAlias(HrDocumentSigningServiceInterface::class, HrDocumentSigningService::class);
		$context->registerServiceAlias(InterviewCalendarServiceInterface::class, InterviewCalendarService::class);
		$context->registerServiceAlias(LearningPeopleFeedInterface::class, LearningPeopleFeed::class);
		$context->registerServiceAlias(LeaveBalanceProjectionServiceInterface::class, LeaveBalanceProjectionService::class);
		$context->registerServiceAlias(LeaveBuySellSettlementServiceInterface::class, LeaveBuySellSettlementService::class);
		$context->registerServiceAlias(LeaveCalendarServiceInterface::class, LeaveCalendarService::class);
		$context->registerServiceAlias(LeaveCostServiceInterface::class, LeaveCostService::class);
		$context->registerServiceAlias(LeaveCoverageWarningInterface::class, LeaveCoverageWarning::class);
		$context->registerServiceAlias(LoonrunFlowRepairServiceInterface::class, LoonrunFlowRepairService::class);
		$context->registerServiceAlias(ManagerAccessStampsInterface::class, ManagerAccessStamps::class);
		$context->registerServiceAlias(ManagerStampRepairInterface::class, ManagerStampRepair::class);
		$context->registerServiceAlias(ObjectWeekHoursInterface::class, ObjectWeekHours::class);
		$context->registerServiceAlias(ObligationsServiceInterface::class, ObligationsService::class);
		$context->registerServiceAlias(OfferEsignServiceInterface::class, OfferEsignService::class);
		$context->registerServiceAlias(OnCallAverageServiceInterface::class, OnCallAverageService::class);
		$context->registerServiceAlias(OrgChartServiceInterface::class, OrgChartService::class);
		$context->registerServiceAlias(PayrollGLPostServiceInterface::class, PayrollGLPostService::class);
		$context->registerServiceAlias(PayrollMutationServiceInterface::class, PayrollMutationService::class);
		$context->registerServiceAlias(PayrollNetPayServiceInterface::class, PayrollNetPayService::class);
		$context->registerServiceAlias(PayrollReproduceServiceInterface::class, PayrollReproduceService::class);
		$context->registerServiceAlias(PayrollRunCheckServiceInterface::class, PayrollRunCheckService::class);
		$context->registerServiceAlias(PayrollRunServiceInterface::class, PayrollRunService::class);
		$context->registerServiceAlias(PayTransparencyServiceInterface::class, PayTransparencyService::class);
		$context->registerServiceAlias(PersonnelBudgetServiceInterface::class, PersonnelBudgetService::class);
		$context->registerServiceAlias(ProformaPayslipServiceInterface::class, ProformaPayslipService::class);
		$context->registerServiceAlias(ReceiptExtractionServiceInterface::class, ReceiptExtractionService::class);
		$context->registerServiceAlias(ReferralServiceInterface::class, ReferralService::class);
		$context->registerServiceAlias(RegisterImporterInterface::class, RegisterImporter::class);
		$context->registerServiceAlias(RegisterPrefillServiceInterface::class, RegisterPrefillService::class);
		$context->registerServiceAlias(ResourceBookingServiceInterface::class, ResourceBookingService::class);
		$context->registerServiceAlias(RetentionExpiryServiceInterface::class, RetentionExpiryService::class);
		$context->registerServiceAlias(RetroAdjustmentServiceInterface::class, RetroAdjustmentService::class);
		$context->registerServiceAlias(RuleAuditServiceInterface::class, RuleAuditService::class);
		$context->registerServiceAlias(RuleTestDataSeederInterface::class, RuleTestDataSeeder::class);
		$context->registerServiceAlias(SalaryBureauExchangeServiceInterface::class, SalaryBureauExchangeService::class);
		$context->registerServiceAlias(SurveyServiceInterface::class, SurveyService::class);
		$context->registerServiceAlias(TeamDigestComposerInterface::class, TeamDigestComposer::class);
		$context->registerServiceAlias(TimeEstimateServiceInterface::class, TimeEstimateService::class);
		$context->registerServiceAlias(TimesheetAggregationServiceInterface::class, TimesheetAggregationService::class);
		$context->registerServiceAlias(TrainingCompetenceWriterInterface::class, TrainingCompetenceWriter::class);
		$context->registerServiceAlias(VacancyMatchServiceInterface::class, VacancyMatchService::class);
		$context->registerServiceAlias(WageTaxRemittanceServiceInterface::class, WageTaxRemittanceService::class);
		$context->registerServiceAlias(WkrServiceInterface::class, WkrService::class);
		$context->registerServiceAlias(WorkingCalendarReaderInterface::class, WorkingCalendarReader::class);
		$context->registerServiceAlias(WorkingHoursServiceInterface::class, WorkingHoursService::class);
		$context->registerServiceAlias(WpmReportServiceInterface::class, WpmReportService::class);
		$context->registerServiceAlias(YearTransitionServiceInterface::class, YearTransitionService::class);
	}//end register()
}//end class
