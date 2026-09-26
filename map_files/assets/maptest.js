let map, placemarks = [], groupsData = [], flightStatusFilters = {}, customLayerFilters = {};
let selectedOrder = [];
let weightById = {};
let svgCache = {};
let popupsDisabled = true;
let multiRoute = null;
let editingRouteId = null;
const UNLOAD_COORDS = [55.3228, 38.6969];
const UNLOAD_ADDRESS = '\u041c\u043e\u0441\u043a\u043e\u0432\u0441\u043a\u0430\u044f \u043e\u0431\u043b., \u0433 \u0412\u043e\u0441\u043a\u0440\u0435\u0441\u0435\u043d\u0441\u043a, \u0443\u043b \u041a\u0438\u0440\u043e\u0432\u0430, \u0434 3 \u0432';

// === TRACKERS AND COLLECTIONS ===
let trackerPlacemarks = [];
let trackerUpdateInterval = null;
let isUpdatingTrackers = false;
let showTransport = true;
let transportDisplayMode = 'active';
let requestsCollection, trackersCollection;
let warehousesCollection;
let warehousesData = [];
let warehouseStockData = {};
let warehousesVisible = true;
let warehouseCreateTargetFieldId = '';
let warehouseAddressGeocoded = false;
let driverCreateInFlight = false;
let driverMenuVisible = false;
let driverMenuItems = [];
const TXT = {
    notSpecified: '\u041d\u0435 \u0443\u043a\u0430\u0437\u0430\u043d',
    requests: '\u0417\u0430\u044f\u0432\u043a\u0438',
    request: '\u0417\u0430\u044f\u0432\u043a\u0430',
    totalWeight: '\u041e\u0431\u0449\u0438\u0439 \u0432\u0435\u0441',
    sender: '\u0413\u0440\u0443\u0437\u043e\u043e\u0442\u043f\u0440\u0430\u0432\u0438\u0442\u0435\u043b\u044c',
    address: '\u0410\u0434\u0440\u0435\u0441',
    baseStatus: '\u041e\u0441\u043d\u043e\u0432\u043d\u043e\u0439 \u0441\u0442\u0430\u0442\u0443\u0441',
    routeStatus: '\u0421\u0442\u0430\u0442\u0443\u0441 \u0440\u0435\u0439\u0441\u0430',
    routeId: 'ID \u0440\u0435\u0439\u0441\u0430',
    status: '\u0421\u0442\u0430\u0442\u0443\u0441',
    category: '\u041a\u0430\u0442\u0435\u0433\u043e\u0440\u0438\u044f',
    availableForPickup: '\u0414\u043e\u0441\u0442\u0443\u043f\u043d\u043e \u043a \u0432\u044b\u0432\u043e\u0437\u0443',
    loading: '\u0417\u0430\u0433\u0440\u0443\u0437\u043a\u0430...',
    noSavedRoutes: '\u041d\u0435\u0442 \u0441\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u043d\u044b\u0445 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u043e\u0432',
    loadError: '\u041e\u0448\u0438\u0431\u043a\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043a\u0438',
    requestsCount: '\u0417\u0430\u044f\u0432\u043e\u043a',
    addressesCount: '\u0410\u0434\u0440\u0435\u0441\u043e\u0432'
};
const UI = {
    pin: '',
    truck: '',
    ruler: '\u0414\u0438\u0441\u0442\u0430\u043d\u0446\u0438\u044f:',
    clock: '\u0412\u0440\u0435\u043c\u044f:',
    bullet: ' \u2022 ',
    emDash: ' \u2014 ',
    kg: '\u043a\u0433',
    km: '\u043a\u043c',
    m: '\u043c',
    min: '\u043c\u0438\u043d',
    hourShort: '\u0447',
    totalWeightLabel: '\u041e\u0431\u0449\u0438\u0439 \u0432\u0435\u0441',
    routeCreate: '\u0421\u043e\u0437\u0434\u0430\u043d\u0438\u0435 \u043d\u043e\u0432\u043e\u0433\u043e \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430',
    routeSave: '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u043c\u0430\u0440\u0448\u0440\u0443\u0442',
    routeUpdate: '\u041e\u0431\u043d\u043e\u0432\u0438\u0442\u044c \u043c\u0430\u0440\u0448\u0440\u0443\u0442',
    routeEditTitle: '\u0420\u0435\u0434\u0430\u043a\u0442\u0438\u0440\u043e\u0432\u0430\u043d\u0438\u0435 \u0440\u0435\u0439\u0441\u0430',
    modalPlanStart: '\u0412\u044b\u0432\u043e\u0437 \u0437\u0430\u043f\u043b\u0430\u043d\u0438\u0440\u043e\u0432\u0430\u043d \u043d\u0430 \u0434\u0430\u0442\u044b',
    modalPlanEnd: '\u041f\u043e',
    modalActualDates: '\u0424\u0430\u043a\u0442\u0438\u0447\u0435\u0441\u043a\u0438\u0435 \u0434\u0430\u0442\u044b \u043f\u0435\u0440\u0435\u0432\u043e\u0437\u043a\u0438',
    modalDriver: '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c / \u043c\u0430\u0448\u0438\u043d\u0430',
    modalCost: '\u0421\u0442\u043e\u0438\u043c\u043e\u0441\u0442\u044c',
    modalSave: '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c/\u041e\u0431\u043d\u043e\u0432\u0438\u0442\u044c',
    modalCancel: '\u041e\u0442\u043c\u0435\u043d\u0430',
    modalToFound: '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438 \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441',
    modalToStarted: '\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c',
    modalStartTitle: '\u041f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0432 \u201c\u0412\u044b\u0432\u043e\u0437 \u043d\u0430\u0447\u0430\u043b\u0441\u044f\u201d',
    modalStartDate: '\u0414\u0430\u0442\u0430 \u043d\u0430\u0447\u0430\u043b\u0430 \u0432\u044b\u0432\u043e\u0437\u0430',
    driverMissing: '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c \u043d\u0435 \u0443\u043a\u0430\u0437\u0430\u043d',
    driverPrefix: '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c #',
    weight: '\u0412\u0435\u0441',
    group: '\u0413\u0440\u0443\u043f\u043f\u0430',
    routePrefix: '\u0420\u0435\u0439\u0441 #'
};
UI.chooseDriver = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044f';
UI.msgFlightDataNotFound = '\u0414\u0430\u043d\u043d\u044b\u0435 \u0440\u0435\u0439\u0441\u0430 \u043d\u0435 \u043d\u0430\u0439\u0434\u0435\u043d\u044b';
UI.msgSetFlightAndDriver = '\u0423\u043a\u0430\u0436\u0438\u0442\u0435 \u0440\u0435\u0439\u0441 \u0438 \u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044f';
UI.msgSaveFailed = '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f';
UI.msgNetworkUpdateFlight = '\u041e\u0448\u0438\u0431\u043a\u0430 \u0441\u0435\u0442\u0438 \u043f\u0440\u0438 \u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u0438 \u0440\u0435\u0439\u0441\u0430';
UI.msgConfirmToFound = '\u0421\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441?';
UI.msgTransferFailed = '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u043f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0440\u0435\u0439\u0441';
UI.msgNetworkTransfer = '\u041e\u0448\u0438\u0431\u043a\u0430 \u0441\u0435\u0442\u0438 \u043f\u0440\u0438 \u043f\u0435\u0440\u0435\u0432\u043e\u0434\u0435 \u0440\u0435\u0439\u0441\u0430';
UI.msgInvalidFlightId = '\u041d\u0435\u043a\u043e\u0440\u0440\u0435\u043a\u0442\u043d\u044b\u0439 ID \u0440\u0435\u0439\u0441\u0430';
UI.msgPickAtLeastOneRequest = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0445\u043e\u0442\u044f \u0431\u044b \u043e\u0434\u043d\u0443 \u0437\u0430\u044f\u0432\u043a\u0443';
UI.msgEnterRouteName = '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u043d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430:';
UI.msgRouteNameEmpty = '\u041d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430 \u043d\u0435 \u043c\u043e\u0436\u0435\u0442 \u0431\u044b\u0442\u044c \u043f\u0443\u0441\u0442\u044b\u043c';
UI.msgSaving = '\u23F3 \u0421\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u0438\u0435...';
UI.msgErrorPrefix = '\u041e\u0448\u0438\u0431\u043a\u0430: ';
UI.msgNetworkError = '\u041e\u0448\u0438\u0431\u043a\u0430 \u0441\u0435\u0442\u0438';
UI.msgDeleteRouteConfirm = '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u043c\u0430\u0440\u0448\u0440\u0443\u0442?';
UI.msgMinTwoRequests = '\u041c\u0438\u043d\u0438\u043c\u0443\u043c 2 \u0437\u0430\u044f\u0432\u043a\u0438';
UI.msgCoordsNotFound = '\u041a\u043e\u043e\u0440\u0434\u0438\u043d\u0430\u0442\u044b \u043d\u0435 \u043d\u0430\u0439\u0434\u0435\u043d\u044b';
UI.labelEditRoute = '\u0420\u0435\u0434\u0430\u043a\u0442\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441';
UI.labelToFound = '\u0412 \u00ab\u0420\u0435\u0439\u0441 \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u043d\u00bb';
UI.labelToStarted = '\u041f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0432 \u0412\u044b\u0432\u043e\u0437 \u043d\u0430\u0447\u0430\u043b\u0441\u044f';
UI.labelBackToPlanned = '\u0412\u0435\u0440\u043d\u0443\u0442\u044c \u0432 \u00ab\u041f\u043b\u0430\u043d\u0438\u0440\u0443\u0435\u043c\u044b\u0439\u00bb';
UI.labelBackToFound = '\u0412\u0435\u0440\u043d\u0443\u0442\u044c \u0432 \u00ab\u0420\u0435\u0439\u0441 \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u043d\u00bb';
UI.labelDriver = '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c';
UI.msgBackToPlanned = '\u0420\u0435\u0439\u0441 #{id} \u0431\u0443\u0434\u0435\u0442 \u0432\u043e\u0437\u0432\u0440\u0430\u0449\u0435\u043d \u0432 \u00ab\u041f\u043b\u0430\u043d\u0438\u0440\u0443\u0435\u043c\u044b\u0439\u00bb.\\n\\n\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c?';
UI.msgBackToFound = '\u0420\u0435\u0439\u0441 #{id} \u0431\u0443\u0434\u0435\u0442 \u0432\u043e\u0437\u0432\u0440\u0430\u0449\u0451\u043d \u0432 \u00ab\u0420\u0435\u0439\u0441 \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u043d\u00bb.\\n\\n\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c?';
UI.msgToCompleted = '\u0420\u0435\u0439\u0441 #{id} \u0431\u0443\u0434\u0435\u0442 \u043f\u0435\u0440\u0435\u0432\u0435\u0434\u0435\u043d \u0432 \u00ab\u0413\u0440\u0443\u0437 \u0441\u0434\u0430\u043d\u00bb.\\n\\n\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c?';
UI.msgNeedEndDate = '\u0423\u043a\u0430\u0436\u0438\u0442\u0435 \u0434\u0430\u0442\u0443 \u0437\u0430\u0432\u0435\u0440\u0448\u0435\u043d\u0438\u044f \u043f\u0435\u0440\u0435\u0432\u043e\u0437\u043a\u0438.';
UI.labelToCompleted = '\u041f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0432 \u0413\u0440\u0443\u0437 \u0441\u0434\u0430\u043d';
UI.msgTransitionValidationHeader = '\u0427\u0442\u043e\u0431\u044b \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441, \u0437\u0430\u043f\u043e\u043b\u043d\u0438\u0442\u0435 \u043e\u0431\u044f\u0437\u0430\u0442\u0435\u043b\u044c\u043d\u044b\u0435 \u043f\u043e\u043b\u044f.';
UI.msgTransitionValidationTitle = '\u043a\u043e\u043c\u043c\u0435\u043d\u0442\u0430\u0440\u0438\u0439 / \u0437\u0430\u0433\u043e\u043b\u043e\u0432\u043e\u043a';
UI.msgTransitionValidationDriver = '\u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044c';
UI.msgTransitionValidationDates = '\u0434\u0430\u0442\u044b';
UI.msgTransitionValidationCost = '\u0441\u0442\u043e\u0438\u043c\u043e\u0441\u0442\u044c';
UI.msgTransitionValidationRequests = '\u0437\u0430\u044f\u0432\u043a\u0438';
UI.msgTransitionValidationRouteType = '\u0442\u0438\u043f \u0440\u0435\u0439\u0441\u0430';
UI.msgTransitionValidationWarehouseSource = '\u0441\u043a\u043b\u0430\u0434 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u044f';
UI.msgTransitionValidationWarehouseDestination = '\u0441\u043a\u043b\u0430\u0434 \u043d\u0430\u0437\u043d\u0430\u0447\u0435\u043d\u0438\u044f';
UI.msgSaveValidationTitle = '\u0414\u043b\u044f \u0441\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u0438\u044f/\u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u044f \u0437\u0430\u043f\u043e\u043b\u043d\u0438\u0442\u0435 \u043e\u0431\u044f\u0437\u0430\u0442\u0435\u043b\u044c\u043d\u044b\u0435 \u043f\u043e\u043b\u044f.';
UI.msgChooseManagerForRoute = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u0430 \u0434\u043b\u044f \u043f\u043b\u0430\u043d\u0438\u0440\u0443\u0435\u043c\u043e\u0433\u043e \u0440\u0435\u0439\u0441\u0430.';
UI.msgChooseManagerOption = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u0430';
UI.msgCreateRouteTitleRequired = '\u041d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430 \u043d\u0435 \u043c\u043e\u0436\u0435\u0442 \u0431\u044b\u0442\u044c \u043f\u0443\u0441\u0442\u044b\u043c';
UI.labelRouteType = '\u0422\u0438\u043f \u0440\u0435\u0439\u0441\u0430';
UI.msgRouteEditLoadFreshFailed = '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0430\u043a\u0442\u0443\u0430\u043b\u044c\u043d\u044b\u0435 \u0434\u0430\u043d\u043d\u044b\u0435 \u0440\u0435\u0439\u0441\u0430. \u041f\u043e\u0432\u0442\u043e\u0440\u0438\u0442\u0435 \u043f\u043e\u043f\u044b\u0442\u043a\u0443.';

// === TRACKER DATA FROM PHP BOOTSTRAP ===
const mapBootstrap = (typeof window !== 'undefined' && window.MAP_BOOTSTRAP && typeof window.MAP_BOOTSTRAP === 'object')
    ? window.MAP_BOOTSTRAP
    : {};
const uiTexts = (mapBootstrap.uiTexts && typeof mapBootstrap.uiTexts === 'object') ? mapBootstrap.uiTexts : {};
function uiText(key, fallback) {
    const value = uiTexts[key];
    if (typeof value !== 'string') return fallback;
    const trimmed = value.trim();
    return trimmed !== '' ? trimmed : fallback;
}
const initialTrackers = Array.isArray(mapBootstrap.initialTrackers) ? mapBootstrap.initialTrackers : [];
const allTrackers = Array.isArray(mapBootstrap.allTrackers) ? mapBootstrap.allTrackers : [];
const routeCardsMeta = (mapBootstrap.routeCardsMeta && typeof mapBootstrap.routeCardsMeta === 'object') ? mapBootstrap.routeCardsMeta : {};
const foundRoutesData = Array.isArray(mapBootstrap.foundRoutesData) ? mapBootstrap.foundRoutesData : [];
const startedRoutesData = Array.isArray(mapBootstrap.startedRoutesData) ? mapBootstrap.startedRoutesData : [];
const driversForSelect = Array.isArray(mapBootstrap.driversForSelect) ? mapBootstrap.driversForSelect : [];
let currentDriversCatalog = Array.isArray(driversForSelect) ? driversForSelect.slice() : [];
const statusNames = (mapBootstrap.statusNames && typeof mapBootstrap.statusNames === 'object') ? mapBootstrap.statusNames : {};
const bootstrapGroupsData = Array.isArray(mapBootstrap.groupsData) ? mapBootstrap.groupsData : [];
const bootstrapFlightStatusList = (mapBootstrap.flightStatusList && typeof mapBootstrap.flightStatusList === 'object') ? mapBootstrap.flightStatusList : {};
const bootstrapCustomLayers = (mapBootstrap.customLayers && typeof mapBootstrap.customLayers === 'object') ? mapBootstrap.customLayers : {};
let currentActiveTrackers = Array.isArray(initialTrackers) ? initialTrackers : [];
let currentAllTrackers = Array.isArray(allTrackers) ? allTrackers : [];
let currentEditingMeta = null;
let currentFoundRoutes = Array.isArray(foundRoutesData) ? foundRoutesData : [];
let currentStartedRoutes = Array.isArray(startedRoutesData) ? startedRoutesData : [];
let managerScopeId = '';
let managerScopeDriverIds = new Set();
let managerScopePlates = new Set();
let managerScopeInitialized = false;
let currentManagers = [];
UI.chooseDriver = uiText('driver.select.placeholder', UI.chooseDriver);
UI.msgPickAtLeastOneRequest = uiText('validation.pick_one_request', UI.msgPickAtLeastOneRequest);
UI.msgDeleteRouteConfirm = uiText('confirm.delete_route', UI.msgDeleteRouteConfirm);
UI.msgSelectRouteToEdit = uiText('route.edit.select_required', 'Выберите рейс для редактирования.');
UI.msgDriverMustPickFromList = uiText('driver.validation.must_select_from_list', 'Выберите водителя из списка.');
UI.msgDriverNameInvalid = uiText('driver.validation.full_name', 'Введите ФИО полностью: Фамилия Имя Отчество');
UI.msgDriverPlateInvalid = uiText('driver.validation.plate', 'Введите госномер в формате А123АА45 или А123АА456');
UI.msgDriverNotFound = uiText('driver.search.not_found', 'Ничего не найдено');
UI.msgWarehouseRequired = uiText('warehouse.validation.required', 'Заполните обязательные поля: название и полный адрес склада.');
UI.msgWarehouseLatInvalid = uiText('warehouse.validation.latitude', 'Некорректная широта.');
UI.msgWarehouseLonInvalid = uiText('warehouse.validation.longitude', 'Некорректная долгота.');
UI.msgWarehouseSaveFailed = uiText('warehouse.save.failed', 'Не удалось сохранить склад.');
UI.msgWarehouseSaveNetwork = uiText('warehouse.save.network_error', 'Ошибка сети при сохранении склада.');
UI.msgWarehouseGeocodeAddressRequired = uiText('warehouse.geocode.address_required', 'Введите полный адрес для определения координат.');
UI.msgWarehouseGeocodeLoading = uiText('warehouse.geocode.loading', 'Определение...');
UI.msgWarehouseGeocodeSuccess = uiText('warehouse.geocode.success', 'Координаты определены.');
UI.msgWarehouseGeocodeNetwork = uiText('warehouse.geocode.network_error', 'Ошибка сети при определении координат.');
UI.msgWarehouseGeocodeChanged = uiText('warehouse.geocode.changed_after_edit', 'Адрес изменён, координаты лучше определить заново.');
UI.labelWarehouseMarker = uiText('warehouse.marker.label', 'СКЛАД');
UI.labelWarehouseName = uiText('warehouse.popup.name', 'Название');
UI.labelWarehouseAddress = uiText('warehouse.popup.address', 'Адрес');
UI.labelWarehouseCoordinates = uiText('warehouse.popup.coordinates', 'Координаты');
UI.msgTransitionValidationRouteType = uiText('route.validation.required.route_type', UI.msgTransitionValidationRouteType);
UI.msgTransitionValidationWarehouseSource = uiText('route.validation.required.warehouse_source', UI.msgTransitionValidationWarehouseSource);
UI.msgTransitionValidationWarehouseDestination = uiText('route.validation.required.warehouse_destination', UI.msgTransitionValidationWarehouseDestination);
UI.msgRouteEditLoadFreshFailed = uiText('route.edit.load_fresh_failed', UI.msgRouteEditLoadFreshFailed);
UI.msgCopied = uiText('common.copied', 'Текст скопирован.');
UI.msgCopyFailed = uiText('common.copy_failed', 'Не удалось скопировать текст.');
UI.msgDriverGpsTypeRequired = uiText('driver.validation.gps_type', 'Выберите тип GPS подключения.');
UI.msgDriverCreateInProgress = uiText('driver.create.in_progress', 'Создание...');
UI.msgDriverCreateFailed = uiText('driver.create.failed', 'Не удалось создать водителя.');
UI.msgDriverCreateServerNoData = uiText('driver.create.server_no_driver', 'Сервер не вернул данные водителя.');
UI.msgDriverCreateExisting = uiText('driver.create.existing_selected', 'Такой водитель уже существует и выбран в форме.');
UI.msgDriverCreateNewTrackerDone = uiText('driver.create.new_tracker_done', 'Трекер настроен.');
UI.msgDriverCreateFreeLeft = uiText('driver.create.free_left', 'Свободных трекеров осталось');
UI.msgDriverCreateRetranslationDone = uiText('driver.create.retranslation_done', 'Водитель создан и выбран в форме. Настройки ретрансляции отправлены в MAX.');
UI.msgDriverCreateNetworkError = uiText('driver.create.network_error', 'Ошибка сети при создании водителя.');
UI.msgDriverGpsHelpRetranslation = uiText('driver.gps.helper.retranslation', 'Для варианта «Ретрансляция» используется тот же алгоритм регистрации трекера. Различается только текст MAX-уведомления.');
UI.msgDriverGpsHelpNewMobile = uiText('driver.gps.helper.new_mobile', 'Для варианта «Новый мобильный трекер» система подберет свободный трекер SLITEX и зарегистрирует его после сохранения водителя.');
const MANAGER_STORAGE_KEY = 'map_selected_manager_id';
let recentActivatedTrackersMap = {};
const foundRoutesById = {};
const startedRoutesById = {};
function rebuildFoundRoutesById() {
    Object.keys(foundRoutesById).forEach(key => delete foundRoutesById[key]);
    Object.keys(startedRoutesById).forEach(key => delete startedRoutesById[key]);
    if (Array.isArray(currentFoundRoutes)) {
        currentFoundRoutes.forEach(route => {
            if (route && route.id !== undefined && route.id !== null) {
                foundRoutesById[String(route.id)] = route;
            }
        });
    }
    if (Array.isArray(currentStartedRoutes)) {
        currentStartedRoutes.forEach(route => {
            if (route && route.id !== undefined && route.id !== null) {
                startedRoutesById[String(route.id)] = route;
            }
        });
    }
}
rebuildFoundRoutesById();

// The backend-prepared active tracker list may occasionally miss a tracker for
// a route that is already in status=started. Keep the backend list as the base,
// then recover only an unambiguous tracker from the complete SLITEX dataset.
// Matching priority is deliberately fail-closed:
//   1) driver's explicit tracker_uniqueid / legacy tracker_id (when exposed),
//   2) tracker matched to this exact flight,
//   3) tracker matched to this exact driver,
//   4) vehicle plate fallback.
// If two different uniqueids tie at the best available priority, nothing is
// injected for that route. This prevents a guessed/duplicate vehicle marker.
function getStartedRouteTrackerIdentity(route) {
    if (!route || typeof route !== 'object') return null;

    const flightIdNumber = Number(route.id || route.flight_id || 0);
    const driverIdNumber = Number(route.driver_id || 0);
    const flightId = Number.isFinite(flightIdNumber) && flightIdNumber > 0 ? String(flightIdNumber) : '';
    const driverId = Number.isFinite(driverIdNumber) && driverIdNumber > 0 ? String(driverIdNumber) : '';

    const driver = driverId ? getDriverById(driverId) : null;
    const trackerUniqueId = driver
        ? String(driver.tracker_uniqueid || driver.tracker_id || '').trim()
        : '';

    let plate = extractPlateFromText(
        route.vehicle_make_plate || route.driver_plate || route.driver_label || ''
    );
    if (!plate && driver) {
        plate = extractPlateFromText(driver.vehicle_make_plate || driver.label || '');
    }

    if (!flightId && !driverId && !trackerUniqueId && !plate) return null;
    return { flightId, driverId, trackerUniqueId, plate };
}

function getStartedRouteTrackerMatchPriority(tracker, identity) {
    if (!tracker || typeof tracker !== 'object' || !identity || !hasTrackerCoords(tracker)) return 0;

    const existingFlightIdNumber = Number(tracker.matched_flight_id || tracker.flight_id || 0);
    const existingDriverIdNumber = Number(tracker.matched_driver_id || 0);
    if (identity.flightId && Number.isFinite(existingFlightIdNumber) && existingFlightIdNumber > 0 &&
        String(existingFlightIdNumber) !== identity.flightId) {
        return 0;
    }
    if (identity.driverId && Number.isFinite(existingDriverIdNumber) && existingDriverIdNumber > 0 &&
        String(existingDriverIdNumber) !== identity.driverId) {
        return 0;
    }

    const uniqueid = getTrackerUniqueId(tracker);
    if (identity.trackerUniqueId && uniqueid && uniqueid === identity.trackerUniqueId) {
        return 4;
    }

    const matchedFlightIdNumber = existingFlightIdNumber;
    if (identity.flightId && Number.isFinite(matchedFlightIdNumber) && matchedFlightIdNumber > 0 &&
        String(matchedFlightIdNumber) === identity.flightId) {
        return 3;
    }

    const matchedDriverIdNumber = existingDriverIdNumber;
    if (identity.driverId && Number.isFinite(matchedDriverIdNumber) && matchedDriverIdNumber > 0 &&
        String(matchedDriverIdNumber) === identity.driverId) {
        return 2;
    }

    const plate = extractPlateFromText(tracker.name || tracker.short_name || '');
    if (identity.plate && plate && plate === identity.plate) {
        return 1;
    }

    return 0;
}

function findUnambiguousTrackerForStartedRoute(route, allTrackersSource) {
    const identity = getStartedRouteTrackerIdentity(route);
    if (!identity) return null;

    let bestPriority = 0;
    const bestByUniqueId = new Map();

    (Array.isArray(allTrackersSource) ? allTrackersSource : []).forEach(tracker => {
        const priority = getStartedRouteTrackerMatchPriority(tracker, identity);
        if (priority <= 0) return;

        if (priority > bestPriority) {
            bestPriority = priority;
            bestByUniqueId.clear();
        }
        if (priority !== bestPriority) return;

        const uniqueid = getTrackerUniqueId(tracker);
        if (!uniqueid) return;
        if (!bestByUniqueId.has(uniqueid)) {
            bestByUniqueId.set(uniqueid, tracker);
        }
    });

    if (bestByUniqueId.size !== 1) return null;
    return bestByUniqueId.values().next().value || null;
}

function bindTrackerToStartedRoute(tracker, route) {
    const bound = { ...tracker };
    const driverId = Number(route && route.driver_id || 0);
    const flightId = Number(route && (route.id || route.flight_id) || 0);

    if ((!Number(bound.matched_driver_id) || Number(bound.matched_driver_id) <= 0) &&
        Number.isFinite(driverId) && driverId > 0) {
        bound.matched_driver_id = driverId;
    }
    if ((!Number(bound.matched_flight_id) || Number(bound.matched_flight_id) <= 0) &&
        Number.isFinite(flightId) && flightId > 0) {
        bound.matched_flight_id = flightId;
    }
    return bound;
}

function supplementActiveTrackersFromStartedRoutes(activeTrackers, allTrackersSource, startedRoutes) {
    const result = [];
    const usedUniqueIds = new Set();

    const pushUnique = (tracker) => {
        if (!hasTrackerCoords(tracker)) return;
        const uniqueid = getTrackerUniqueId(tracker);
        if (uniqueid && usedUniqueIds.has(uniqueid)) return;
        if (uniqueid) usedUniqueIds.add(uniqueid);
        result.push(tracker);
    };

    (Array.isArray(activeTrackers) ? activeTrackers : []).forEach(pushUnique);

    (Array.isArray(startedRoutes) ? startedRoutes : []).forEach(route => {
        const tracker = findUnambiguousTrackerForStartedRoute(route, allTrackersSource);
        if (tracker) pushUnique(bindTrackerToStartedRoute(tracker, route));
    });

    return result;
}

function getTrackersForCurrentMode() {
    if (transportDisplayMode === 'none') return [];
    const allDataset = Array.isArray(currentAllTrackers) && currentAllTrackers.length
        ? currentAllTrackers
        : (Array.isArray(currentActiveTrackers) ? currentActiveTrackers : []);

    if (transportDisplayMode === 'all') {
        return allDataset.filter(hasTrackerCoords);
    }

    const activeWithStartedRoutes = supplementActiveTrackersFromStartedRoutes(
        Array.isArray(currentActiveTrackers) ? currentActiveTrackers : [],
        allDataset,
        currentStartedRoutes
    );
    const activeDataset = managerFilteredTrackers(activeWithStartedRoutes);
    return mergeWithRecentActivatedTrackers(activeDataset, allDataset);
}

function renderTrackersByMode() {
    addTrackerMarkers(getTrackersForCurrentMode());
}

function hexToRgba(hex, alpha) {
    const safeHex = String(hex || '').trim();
    const match = safeHex.match(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/);
    if (!match) return `rgba(0,0,0,${alpha})`;
    let value = match[1];
    if (value.length === 3) {
        value = value.split('').map(ch => ch + ch).join('');
    }
    const num = parseInt(value, 16);
    const r = (num >> 16) & 255;
    const g = (num >> 8) & 255;
    const b = num & 255;
    return `rgba(${r},${g},${b},${alpha})`;
}

// === TRACKER FUNCTIONS ===
function getTrackerPreset(minutes) {
    if (minutes < 10) return 'islands#blueStretchyIcon';
    if (minutes < 180) return 'islands#orangeStretchyIcon';
    return 'islands#redStretchyIcon';
}

function getTrackerMinutes(tracker) {
    if (!tracker || typeof tracker !== 'object') return 9999;
    const rawMinutes = tracker.time_diff_minutes;
    if (rawMinutes === null || rawMinutes === undefined || rawMinutes === '') {
        return 9999;
    }
    const minutes = Number(rawMinutes);
    return Number.isFinite(minutes) ? minutes : 9999;
}

function hasTrackerCoords(tracker) {
    if (!tracker || typeof tracker !== 'object') return false;
    if (tracker.lat === null || tracker.lat === undefined || String(tracker.lat).trim() === '') return false;
    if (tracker.lon === null || tracker.lon === undefined || String(tracker.lon).trim() === '') return false;

    const lat = Number(tracker.lat);
    const lon = Number(tracker.lon);
    return Number.isFinite(lat) && Number.isFinite(lon) &&
        lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180;
}

function getTrackerUniqueId(tracker) {
    if (!tracker || tracker.uniqueid === undefined || tracker.uniqueid === null) return '';
    return String(tracker.uniqueid).trim();
}

function isRecentActivatedTracker(tracker) {
    const uniqueid = getTrackerUniqueId(tracker);
    return uniqueid !== '' && !!recentActivatedTrackersMap[uniqueid];
}

function isTrackerAssignedToRoute(tracker) {
    if (!tracker || typeof tracker !== 'object') return false;
    const matchedDriverId = Number(tracker.matched_driver_id || 0);
    if (Number.isFinite(matchedDriverId) && matchedDriverId > 0) return true;

    const matchedFlightId = Number(tracker.matched_flight_id || tracker.flight_id || 0);
    if (Number.isFinite(matchedFlightId) && matchedFlightId > 0) return true;

    return false;
}

function mergeWithRecentActivatedTrackers(baseTrackers, allTrackersSource) {
    const result = [];
    const usedUniqueIds = new Set();

    (Array.isArray(baseTrackers) ? baseTrackers : []).forEach(tracker => {
        if (!hasTrackerCoords(tracker)) return;
        const uniqueid = getTrackerUniqueId(tracker);

        // A tracker already present in the active-route dataset must win over the
        // generic SLITEX dataset. Mark its uniqueid as used immediately, even when
        // it is assigned to a route and therefore must not receive the NEW badge.
        // Otherwise the same physical tracker is appended a second time below as
        // a recent tracker, producing two overlapping markers with different colors.
        if (uniqueid) {
            if (usedUniqueIds.has(uniqueid)) return;
            usedUniqueIds.add(uniqueid);
        }

        const merged = { ...tracker };
        if (uniqueid && recentActivatedTrackersMap[uniqueid] && !isTrackerAssignedToRoute(tracker)) {
            merged.is_new_tracker = true;
            merged.first_activation_at = String(recentActivatedTrackersMap[uniqueid].first_activation_at || '');
        }
        result.push(merged);
    });

    (Array.isArray(allTrackersSource) ? allTrackersSource : []).forEach(tracker => {
        if (!hasTrackerCoords(tracker)) return;
        const uniqueid = getTrackerUniqueId(tracker);
        if (!uniqueid || !recentActivatedTrackersMap[uniqueid] || usedUniqueIds.has(uniqueid)) return;
        if (isTrackerAssignedToRoute(tracker)) return;

        result.push({
            ...tracker,
            is_new_tracker: true,
            first_activation_at: String(recentActivatedTrackersMap[uniqueid].first_activation_at || '')
        });
        usedUniqueIds.add(uniqueid);
    });

    return result;
}

async function loadRecentActivatedTrackers() {
    try {
        const response = await fetch('map_files/get_tracker_first_activation.php', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        if (data && data.success && data.trackers && typeof data.trackers === 'object') {
            recentActivatedTrackersMap = data.trackers;
        } else {
            recentActivatedTrackersMap = {};
        }
    } catch (e) {
        console.warn('Activation data unavailable', e);
        recentActivatedTrackersMap = {};
    }
}

function isUserEditing() {
    const visibleModalIds = ['flightEditModal', 'driverCreateModal', 'warehouseCreateModal', 'startConfirmModal'];
    for (const id of visibleModalIds) {
        const el = document.getElementById(id);
        if (el && el.style && el.style.display !== 'none') {
            return true;
        }
    }
    const active = document.activeElement;
    if (active && active.closest) {
        if (active.closest('#flightEditModal, #driverCreateModal, #warehouseCreateModal, #startConfirmModal')) {
            return true;
        }
    }
    return false;
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, function(m) {
        const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return map[m] || m;
    });
}

function renderSafeStaticHtml(value) {
    const escaped = escapeHtml(String(value || ''));
    return escaped
        .replace(/&lt;br\s*\/?&gt;/gi, '<br>')
        .replace(/&lt;strong&gt;/gi, '<strong>')
        .replace(/&lt;\/strong&gt;/gi, '</strong>')
        .replace(/\r\n|\r|\n/g, '<br>');
}

function setSafeStaticHtml(element, value) {
    if (!element) return;
    element.classList.add('static-text-rendered');
    element.innerHTML = renderSafeStaticHtml(value);
}

function createTrackerBalloon(tracker) {
    const newTrackerBadge = tracker && tracker.is_new_tracker
        ? `<br><span style="display:inline-block; margin-top:6px; font-size:11px; font-weight:700; color:#0d47a1;">НОВЫЙ ТРЕКЕР</span>`
        : '';
    return `<div style="padding:12px; font-family:Arial,sans-serif; max-width:280px;">
        <b>${UI.truck} ${escapeHtml(tracker.name)}</b><br>
        <span style="color:#666; font-size:12px;">ID: ${tracker.uniqueid}</span><br>
        <span style="color:#888; font-size:11px;">${UI.clock} ${tracker.time_diff_text} \u043d\u0430\u0437\u0430\u0434</span><br>
        <span style="color:#555; font-size:11px;">${UI.pin} ${Number(tracker.lat).toFixed(5)}, ${Number(tracker.lon).toFixed(5)}</span>${newTrackerBadge}
    </div>`;
}

function extractPlateFromText(value) {
    const text = String(value || '').toUpperCase();
    const m = text.match(/[\u0410-\u042f\u0401A-Z]\d{3}[\u0410-\u042f\u0401A-Z]{2}\d{2,3}/u);
    return m ? m[0] : '';
}

function rebuildManagerScopeKeys(plannedRoutes, foundRoutes, startedRoutes) {
    managerScopeDriverIds = new Set();
    managerScopePlates = new Set();
    const allRoutes = []
        .concat(Array.isArray(plannedRoutes) ? plannedRoutes : [])
        .concat(Array.isArray(foundRoutes) ? foundRoutes : [])
        .concat(Array.isArray(startedRoutes) ? startedRoutes : []);
    allRoutes.forEach(route => {
        if (!route || typeof route !== 'object') return;
        const driverId = Number(route.driver_id || 0);
        if (driverId > 0) managerScopeDriverIds.add(String(driverId));
        const plate = extractPlateFromText(route.driver_label || route.name || '');
        if (plate) managerScopePlates.add(plate);
    });
}

function managerFilteredTrackers(trackers) {
    if (!managerScopeId) return trackers;
    if (!managerScopeDriverIds.size && !managerScopePlates.size) return [];

    return (Array.isArray(trackers) ? trackers : []).filter(tracker => {
        if (!tracker || typeof tracker !== 'object') return false;
        const matchedDriver = tracker.matched_driver_id ? String(tracker.matched_driver_id) : '';
        if (matchedDriver && managerScopeDriverIds.has(matchedDriver)) return true;
        const plate = extractPlateFromText(tracker.name || tracker.short_name || '');
        if (plate && managerScopePlates.has(plate)) return true;
        return false;
    });
}

function formatDriverCompactLabel(label) {
    const value = String(label || '').trim();
    if (!value || value === UI.driverMissing) return UI.driverMissing;
    const plateMatch = value.match(/[\u0410-\u042f\u0401A-Z]\d{3}[\u0410-\u042f\u0401A-Z]{2}\d{2,3}/u);
    let surname = '';

    // Extract surname from the LAST bracket pair — the first one may contain vehicle model,
    // while the last one (from drivers.full_name) contains the actual driver name.
    const allBrackets = value.match(/\(([^)]+)\)/gu);
    if (allBrackets && allBrackets.length > 0) {
        const lastNameInBrackets = allBrackets[allBrackets.length - 1];
        const inner = lastNameInBrackets.replace(/^\(|\)$/g, '');
        surname = String(inner).trim().split(/\s+/u)[0] || '';
    }
    if (!surname) {
        const beforeSlash = String(value.split('/')[0] || '').trim();
        const words = beforeSlash.match(/[\u0410-\u042F\u0401A-Z][\u0430-\u044F\u0451a-z]+/gu);
        if (words && words.length) {
            surname = words[0];
        }
    }

    if (plateMatch && surname) return `${plateMatch[0]}(${surname})`;
    if (plateMatch) return plateMatch[0];
    if (surname) return surname;
    return UI.driverMissing;
}

function formatRouteCost(costValue) {
    if (costValue === null || costValue === undefined || costValue === '') return '0';
    const num = Number(costValue);
    if (!Number.isFinite(num)) return String(costValue);
    return num.toLocaleString('ru-RU');
}

function normalizeUnloadType(value) {
    return String(value || '').toUpperCase() === 'SKLAD' ? 'SKLAD' : 'OO';
}

function normalizeRouteType(value, unloadTypeValue) {
    const v = String(value || '').trim().toLowerCase();
    const allowed = new Set([
        'generator_to_utilizer',
        'generator_to_warehouse',
        'warehouse_to_warehouse',
        'warehouse_to_utilizer'
    ]);
    if (allowed.has(v)) return v;
    return normalizeUnloadType(unloadTypeValue) === 'SKLAD'
        ? 'generator_to_warehouse'
        : 'generator_to_utilizer';
}

function resolveUnloadTypeByRouteType(routeType) {
    return normalizeRouteType(routeType, 'OO') === 'generator_to_utilizer' ? 'OO' : 'SKLAD';
}

function getRouteTypeLabel(routeTypeRaw, unloadTypeRaw = 'OO') {
    const routeType = normalizeRouteType(routeTypeRaw, unloadTypeRaw);
    if (routeType === 'generator_to_warehouse') return '\u0412\u044b\u0433\u0440\u0443\u0437\u043a\u0430 \u043d\u0430 \u0441\u043a\u043b\u0430\u0434';
    if (routeType === 'warehouse_to_warehouse') return '\u0421\u043a\u043b\u0430\u0434 \u2192 \u0421\u043a\u043b\u0430\u0434';
    if (routeType === 'warehouse_to_utilizer') return '\u0421\u043a\u043b\u0430\u0434 \u2192 \u0423\u0442\u0438\u043b\u0438\u0437\u0430\u0442\u043e\u0440';
    return '\u041e\u0431\u044b\u0447\u043d\u0430\u044f \u0432\u044b\u0433\u0440\u0443\u0437\u043a\u0430 / \u0423\u0442\u0438\u043b\u0438\u0437\u0430\u0442\u043e\u0440';
}

function getRouteTypeCompactSuffix(routeTypeRaw, unloadTypeRaw = 'OO') {
    const routeType = normalizeRouteType(routeTypeRaw, unloadTypeRaw);
    if (routeType === 'generator_to_warehouse') return `${UI.bullet}<strong>\u2192 \u0421\u043a\u043b\u0430\u0434</strong>`;
    if (routeType === 'warehouse_to_warehouse') return `${UI.bullet}<strong>\u0421\u043a\u043b\u0430\u0434 \u2192 \u0421\u043a\u043b\u0430\u0434</strong>`;
    if (routeType === 'warehouse_to_utilizer') return `${UI.bullet}<strong>\u0421\u043a\u043b\u0430\u0434 \u2192 \u0423\u0442\u0438\u043b.</strong>`;
    return '';
}

function updateFlightModalSummary() {
    const summary = document.getElementById('flightLiveSummary');
    if (!summary || !currentEditingMeta) return;

    const zayavkiRaw = document.getElementById('edit_zayavki_ids')?.value || '';
    const ids = zayavkiRaw
        .split(',')
        .map(v => v.trim())
        .filter(v => /^\d+$/.test(v));
    const uniqueIds = Array.from(new Set(ids));

    const isStarted = currentEditingMeta && String(currentEditingMeta.status) === 'started';
    const fromVal = (isStarted ? document.getElementById('edit_actual_start_date') : document.getElementById('edit_planned_start_date_from'))?.value || '';
    const toVal = (isStarted ? document.getElementById('edit_actual_end_date') : document.getElementById('edit_planned_start_date_to'))?.value || '';
    const costVal = document.getElementById('edit_cost')?.value || '';
    const driverSelect = document.getElementById('edit_driver_id');
    const driverText = driverSelect?.selectedOptions?.[0]?.textContent || UI.driverMissing;
    const routeTypeValue = document.getElementById('edit_route_type')?.value || currentEditingMeta.route_type || '';

    let totalTons = 0;
    if (Array.isArray(groupsData)) {
        groupsData.forEach(group => {
            if (!group || !Array.isArray(group.points)) return;
            group.points.forEach(point => {
                if (uniqueIds.includes(String(point.zayavka_id))) {
                    totalTons += Number(point.mass_netto || 0);
                }
            });
        });
    }
    const totalKg = Math.round(totalTons * 1000);
    const periodText = fromVal || toVal ? `${fromVal || TXT.notSpecified} ${UI.emDash} ${toVal || TXT.notSpecified}` : TXT.notSpecified;
    const statusLabel = statusNames[currentEditingMeta.status] || currentEditingMeta.status || TXT.notSpecified;
    const statusClass = currentEditingMeta.status === 'planned_route'
        ? 'flight-status-planned'
        : (currentEditingMeta.status === 'found'
            ? 'flight-status-found'
            : (currentEditingMeta.status === 'started' ? 'flight-status-started' : ''));

    summary.innerHTML = `
        <div><strong>${TXT.requestsCount}:</strong> ${uniqueIds.length}</div>
        <div><strong>${TXT.totalWeight}:</strong> ${totalKg.toLocaleString('ru-RU')} ${UI.kg}</div>
        <div><strong>${UI.modalCost}:</strong> ${formatRouteCost(costVal)} \u20BD</div>
        <div><strong>${UI.labelRouteType}:</strong> ${escapeHtml(getRouteTypeLabel(routeTypeValue, currentEditingMeta.unload_type || 'OO'))}</div>
        <div><strong>${UI.labelDriver}:</strong> ${escapeHtml(formatDriverCompactLabel(driverText))}</div>
        <div><strong>${TXT.routeStatus}:</strong> <span class="${statusClass}">${escapeHtml(statusLabel)}</span></div>
        <div><strong>\u041f\u0435\u0440\u0438\u043e\u0434:</strong> ${escapeHtml(periodText)}</div>
    `;
}

function buildFoundChangePreview(meta) {
    if (!meta || (meta.status !== 'found' && meta.status !== 'started')) return '';
    const isStarted = meta.status === 'started';
    const fromVal = (isStarted ? document.getElementById('edit_actual_start_date') : document.getElementById('edit_planned_start_date_from'))?.value || '';
    const toVal = (isStarted ? document.getElementById('edit_actual_end_date') : document.getElementById('edit_planned_start_date_to'))?.value || '';
    const costVal = document.getElementById('edit_cost')?.value || '';
    const zayavkiVal = document.getElementById('edit_zayavki_ids')?.value || '';
    const driverSelect = document.getElementById('edit_driver_id');
    const currentDriverText = driverSelect?.selectedOptions?.[0]?.textContent || UI.driverMissing;
    const currentRouteType = normalizeRouteType(document.getElementById('edit_route_type')?.value || '', meta.unload_type || 'OO');
    const previousDriver = meta.driver_label || UI.driverMissing;
    const previousDates = isStarted
        ? `${meta.actual_start_date || TXT.notSpecified} \u2014 ${meta.actual_end_date || TXT.notSpecified}`
        : `${meta.planned_start_date_from || TXT.notSpecified} \u2014 ${meta.planned_start_date_to || TXT.notSpecified}`;
    const currentDates = `${fromVal || TXT.notSpecified} \u2014 ${toVal || TXT.notSpecified}`;
    const previousCost = `${formatRouteCost(meta.cost)} \u20BD`;
    const currentCost = `${formatRouteCost(costVal)} \u20BD`;
    const previousIdsArr = String(meta.zayavki_ids || '').split(',').map(v => v.trim()).filter(Boolean);
    const currentIdsArr = zayavkiVal.split(',').map(v => v.trim()).filter(Boolean);
    const previousIds = previousIdsArr.join(',');
    const currentIds = currentIdsArr.join(',');
    const previousRouteType = normalizeRouteType(meta.route_type || '', meta.unload_type || 'OO');

    const blocks = [];
    if (formatDriverCompactLabel(previousDriver) !== formatDriverCompactLabel(currentDriverText)) {
        blocks.push(`\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c:\n\u0411\u044b\u043b\u043e: ${formatDriverCompactLabel(previousDriver)}\n\u0421\u0442\u0430\u043b\u043e: ${formatDriverCompactLabel(currentDriverText)}`);
    }
    if (previousDates !== currentDates) {
        const periodLabel = isStarted ? '\u0424\u0430\u043a\u0442\u0438\u0447\u0435\u0441\u043a\u0438\u0439 \u043f\u0435\u0440\u0438\u043e\u0434' : '\u041f\u0435\u0440\u0438\u043e\u0434';
        blocks.push(`${periodLabel}:\n\u0411\u044b\u043b\u043e: ${previousDates}\n\u0421\u0442\u0430\u043b\u043e: ${currentDates}`);
    }
    if (previousCost !== currentCost) {
        blocks.push(`\u0421\u0442\u043e\u0438\u043c\u043e\u0441\u0442\u044c:\n\u0411\u044b\u043b\u043e: ${previousCost}\n\u0421\u0442\u0430\u043b\u043e: ${currentCost}`);
    }
    if (previousIds !== currentIds) {
        const prevSet = new Set(previousIdsArr);
        const currSet = new Set(currentIdsArr);
        const removed = previousIdsArr.filter(id => !currSet.has(id));
        const added = currentIdsArr.filter(id => !prevSet.has(id));
        if (removed.length > 0) {
            blocks.push(`\u0418\u0441\u043a\u043b\u044e\u0447\u0435\u043d\u043d\u044b\u0435 \u0437\u0430\u044f\u0432\u043a\u0438: ${removed.join(',')}`);
        }
        if (added.length > 0) {
            blocks.push(`\u0414\u043e\u0431\u0430\u0432\u043b\u0435\u043d\u043d\u044b\u0435 \u0437\u0430\u044f\u0432\u043a\u0438: ${added.join(',')}`);
        }
        if (previousIdsArr.length !== currentIdsArr.length) {
            blocks.push(`\u041a\u043e\u043b\u0438\u0447\u0435\u0441\u0442\u0432\u043e \u0437\u0430\u044f\u0432\u043e\u043a:\n\u0411\u044b\u043b\u043e: ${previousIdsArr.length}\n\u0421\u0442\u0430\u043b\u043e: ${currentIdsArr.length}`);
        }
    }
    if (previousRouteType !== currentRouteType) {
        blocks.push(`\u041c\u0430\u0440\u0448\u0440\u0443\u0442 \u0433\u0440\u0443\u0437\u0430:\n\u0411\u044b\u043b\u043e: ${getRouteTypeLabel(previousRouteType, meta.unload_type || 'OO')}\n\u0421\u0442\u0430\u043b\u043e: ${getRouteTypeLabel(currentRouteType, resolveUnloadTypeByRouteType(currentRouteType))}`);
    }
    if (blocks.length === 0) return '';
    const htmlBlocks = blocks.map(b => `<div style="white-space:pre-wrap;margin:6px 0">${escapeHtml(b)}</div>`);
    return `<div><strong>\u0411\u0443\u0434\u0443\u0442 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u044b \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f \u0432 MAX:</strong></div>${htmlBlocks.join('')}`;
}
function addTrackerMarkers(trackers) {
    // Remove old transport markers from collection
    trackerPlacemarks.forEach(pm => trackersCollection.remove(pm));
    trackerPlacemarks = [];
    
    if (!showTransport) return;
    
    trackers.forEach(tracker => {
        if (!tracker || typeof tracker !== 'object') return;
        if (!hasTrackerCoords(tracker)) return;
        const minutes = getTrackerMinutes(tracker);
        const markerOptions = {
            preset: getTrackerPreset(minutes),
            iconImageScale: 0.7,
            iconContentOffset: [0, -7],
            visible: showTransport,
            zIndex: 1000 // Transport is always above request markers
        };
        const placemark = new ymaps.Placemark(
            [Number(tracker.lat), Number(tracker.lon)],
            {
                iconContent: tracker.short_name,
                hintContent: `${tracker.name} | ${tracker.time_diff_text} ${'\u043d\u0430\u0437\u0430\u0434'}`,
                balloonContent: createTrackerBalloon(tracker),
                trackerData: tracker
            },
            markerOptions
        );
        trackerPlacemarks.push(placemark);
        trackersCollection.add(placemark);
    });
}

async function fetchTrackers() {
    if (isUpdatingTrackers) return;
    isUpdatingTrackers = true;
    
    const btn = document.getElementById('refreshBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '\u23F3 ...';
    btn.disabled = true;
    
    try {
        await loadRecentActivatedTrackers();
        const response = await fetch(window.location.href, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const html = await response.text();
        const activeMatch = html.match(/const initialTrackers = (\[[\s\S]*?\]);/);
        const allMatch = html.match(/const allTrackers = (\[[\s\S]*?\]);/);
        if (activeMatch) {
            const parsedActive = JSON.parse(activeMatch[1]);
            currentActiveTrackers = Array.isArray(parsedActive) ? parsedActive : [];
        }
        if (allMatch) {
            const parsedAll = JSON.parse(allMatch[1]);
            currentAllTrackers = Array.isArray(parsedAll) ? parsedAll : [];
        } else {
            currentAllTrackers = [];
        }
        renderTrackersByMode();
    } catch(e) {
        console.error('\u041e\u0448\u0438\u0431\u043a\u0430 \u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u044f \u0442\u0440\u0435\u043a\u0435\u0440\u043e\u0432:', e);
    } finally {
        isUpdatingTrackers = false;
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function toggleTransportVisibility(hide) {
    if (hide) {
        setTransportDisplayMode('none');
    } else {
        setTransportDisplayMode('active');
    }
}

function setTransportDisplayMode(mode) {
    if (mode !== 'active' && mode !== 'all' && mode !== 'none') {
        mode = 'active';
    }
    transportDisplayMode = mode;
    showTransport = mode !== 'none';

    const activeRadio = document.getElementById('transport_mode_active');
    const allRadio = document.getElementById('transport_mode_all');
    const noneRadio = document.getElementById('transport_mode_none');
    if (activeRadio) activeRadio.checked = mode === 'active';
    if (allRadio) allRadio.checked = mode === 'all';
    if (noneRadio) noneRadio.checked = mode === 'none';

    renderTrackersByMode();
}

// === MAIN FUNCTIONS ===
function createMarkerSVG(totalMass, inFlight, markerColor = '#000000', flightId = null, hideTriangle = false, isSelected = false) {
    const key = `${totalMass}_${inFlight}_${markerColor}_${flightId}_${hideTriangle}_${isSelected}`;
    if (svgCache[key]) return svgCache[key];
    const weightInKg = Math.round(totalMass * 1000);
    let bgColor = isSelected ? '#f1f6ff' : '#ffffff';
    if (weightInKg > 10000 && !isSelected) bgColor = '#fd4221';
    else if (weightInKg > 5000 && !isSelected) bgColor = '#FF7400';
    else if (weightInKg > 3000 && !isSelected) bgColor = '#FFA459';
    else if (weightInKg > 1000 && !isSelected) bgColor = '#FFCBA1';
    const statusColor = markerColor || '#000000';
    const strokeColor = isSelected ? '#1f73ff' : statusColor;
    const selectedAccentColor = '#1f73ff';
    const textColor = isSelected ? '#174ea6' : '#000000';
    const strokeWidth = isSelected ? 2.6 : 2;
    const filter = isSelected
        ? `filter="drop-shadow(0 0 2.8px ${hexToRgba(selectedAccentColor, 0.5)})"`
        : '';
    const h = 25, w = 40, bw = 18, bh = 12;
    let svg = `<svg width="${w+10}" height="${h+20}" viewBox="0 0 ${w+10} ${h+20}" xmlns="http://www.w3.org/2000/svg">`;
    svg += `<rect x="1.5" y="16.5" width="${w-3}" height="${h-3}" rx="6" ry="6" fill="${bgColor}" stroke="${strokeColor}" stroke-width="${strokeWidth}" ${filter}/>`;
    svg += `<text x="${w/2}" y="${h/2 + 19}" font-family="Arial" font-size="11" fill="${textColor}" text-anchor="middle" font-weight="bold">${weightInKg}</text>`;
    if (inFlight && flightId) { const bx = w - bw + 10, by = 8; svg += `<rect x="${bx}" y="${by}" width="${bw}" height="${bh}" rx="2" ry="2" fill="${isSelected ? selectedAccentColor : statusColor}"/><text x="${bx + bw/2}" y="${by + bh - 3}" font-family="Arial" font-size="8" fill="#fff" text-anchor="middle" font-weight="bold">#${flightId}</text>`; }
    if (!hideTriangle) { svg += `<polygon points="${w/2-5},${h+13.5} ${w/2+5},${h+13.5} ${w/2},${h+18.5}" fill="${strokeColor}"/>`; }
    svg += '</svg>';
    const uri = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    svgCache[key] = uri; return uri;
}

function createBalloonContent(group) {
    const totalWeightKg = Math.round(group.total_mass * 1000); const firstPoint = group.points[0];
    let html = '<div class="custom-balloon">';
    if (group.count > 1) {
        html += `<h4>${UI.pin} ${TXT.requests} <span class="group-badge">${group.count} \u0448\u0442.</span></h4>`;
        html += `<div class="info-row"><span class="info-label">${TXT.totalWeight}:</span> <strong>${totalWeightKg} ${UI.kg}</strong></div>`;
        html += `<div class="info-row"><span class="info-label">${TXT.sender}:</span> ${firstPoint.naim_oo_gruzootpravitel || TXT.notSpecified}</div>`;
        html += `<div class="info-row"><span class="info-label">${TXT.address}:</span> ${firstPoint.mno_adres_pogruzki || TXT.notSpecified}</div>`;
        if (group.in_flight && group.flight_status) { html += `<div class="info-row"><span class="info-label">${TXT.routeStatus}:</span> <span style="color: ${group.marker_color}; font-weight: bold;">${statusNames[group.flight_status] || group.flight_status}</span></div>`; if (group.flight_id) html += `<div class="info-row"><span class="info-label">${TXT.routeId}:</span> <strong>${group.flight_id}</strong></div>`; }
        else if (!group.in_flight) { if (group.custom_layer) html += `<div class="info-row"><span class="info-label">${TXT.category}:</span> <span style="color: ${group.marker_color}; font-weight: bold;">${group.custom_layer}</span></div>`; else html += `<div class="info-row"><span class="info-label">${TXT.status}:</span> <span style="color: #000; font-weight: bold;">${TXT.availableForPickup}</span></div>`; }
        html += `<div class="points-list">`; group.points.forEach(p => html += `<div class="point-item"><span class="point-number">#${p.zayavka_id}</span><span class="point-weight">${Math.round(p.mass_netto * 1000)} ${UI.kg}</span></div>`); html += '</div>';
    } else {
        html += `<h4>${UI.pin} ${TXT.request} #${firstPoint.zayavka_id}</h4>`;
        html += `<div class="info-row"><span class="info-label">${UI.weight}:</span> <strong>${totalWeightKg} ${UI.kg}</strong></div>`;
        html += `<div class="info-row"><span class="info-label">${TXT.sender}:</span> ${firstPoint.naim_oo_gruzootpravitel || TXT.notSpecified}</div>`;
        html += `<div class="info-row"><span class="info-label">${TXT.address}:</span> ${firstPoint.mno_adres_pogruzki || TXT.notSpecified}</div>`;
        html += `<div class="info-row"><span class="info-label">${TXT.baseStatus}:</span> ${firstPoint.zakaz_s_ot_status || TXT.notSpecified}</div>`;
        if (group.in_flight && group.flight_status) { html += `<div class="info-row"><span class="info-label">${TXT.routeStatus}:</span> <span style="color: ${group.marker_color}; font-weight: bold;">${statusNames[group.flight_status] || group.flight_status}</span></div>`; if (group.flight_id) html += `<div class="info-row"><span class="info-label">${TXT.routeId}:</span> <strong>${group.flight_id}</strong></div>`; }
        else if (!group.in_flight) { if (group.custom_layer) html += `<div class="info-row"><span class="info-label">${TXT.category}:</span> <span style="color: ${group.marker_color}; font-weight: bold;">${group.custom_layer}</span></div>`; else html += `<div class="info-row"><span class="info-label">${TXT.status}:</span> <span style="color: #000; font-weight: bold;">${TXT.availableForPickup}</span></div>`; }
    }
    html += '</div>'; return html;
}

function updateSelectionUI() {
    const panel = document.getElementById('selection-panel');
    const list = document.getElementById('selected-list');
    const totalEl = document.getElementById('total-weight');
    const statsEl = document.getElementById('stats-box');
    const input = document.getElementById('route-input');
    const calcBtn = document.getElementById('calc-route-btn');
    const saveBtn = document.getElementById('saveRouteBtn');
    
    if (selectedOrder.length === 0) {
        panel.style.display = 'none';
        if(calcBtn) calcBtn.disabled = true;
        if(saveBtn) saveBtn.disabled = true;
        return;
    }
    
    panel.style.display = 'block';
    list.innerHTML = '';
    let totalW = 0;
    let uniqueAddresses = new Set();
    
    selectedOrder.forEach(id => {
        const w = weightById[id] || 0;
        totalW += w;
        groupsData.forEach(g => g.points.forEach(p => {
            if (String(p.zayavka_id) === String(id) && p.mno_adres_pogruzki) uniqueAddresses.add(p.mno_adres_pogruzki);
        }));
        const item = document.createElement('div');
        item.className = 'selected-item';
        item.innerHTML = `<span>#${id}${UI.emDash}${w} ${UI.kg}</span><button onclick="removeSelectedId('${id}')">\u00D7</button>`;
        list.appendChild(item);
    });
    
    statsEl.textContent = `${TXT.requestsCount}: ${selectedOrder.length} | ${TXT.addressesCount}: ${uniqueAddresses.size}`;
    totalEl.textContent = `${UI.totalWeightLabel}: ${totalW.toLocaleString('ru-RU')} ${UI.kg}`;
    input.value = selectedOrder.join(', ');
    calcBtn.disabled = selectedOrder.length < 2;
    saveBtn.disabled = false;
}

function refreshMarkerStyles() {
    placemarks.forEach(pm => {
        if (!pm.options.get('visible')) return;
        const g = pm.properties.get('groupData');
        const hideTriangle = pm.properties.get('hideTriangle');
        const isSelected = g.points.some(p => selectedOrder.includes(String(p.zayavka_id)));
        pm.options.set('iconImageHref', createMarkerSVG(g.total_mass, g.in_flight, g.marker_color, g.flight_id, hideTriangle, isSelected));
    });
}

function handleRouteInput() {
    const input = document.getElementById('route-input');
    const raw = input.value;
    const ids = raw.split(/[,;\s\n]+/).map(s => s.trim()).filter(s => /^\d+$/.test(s));
    selectedOrder = ids;
    refreshMarkerStyles();
    updateSelectionUI();
}

document.addEventListener('DOMContentLoaded', () => {
    applySafeModalText();
    const input = document.getElementById('route-input');
    if (input) {
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleRouteInput();
            }
        });
        input.addEventListener('paste', () => setTimeout(handleRouteInput, 10));
        input.addEventListener('blur', handleRouteInput);
    }
});

function toggleGroupSelection(group) {
    const ids = group.points.map(p => String(p.zayavka_id));
    const allSelected = ids.every(id => selectedOrder.includes(id));
    
    if (allSelected) {
        ids.forEach(id => {
            const index = selectedOrder.indexOf(id);
            if (index !== -1) selectedOrder.splice(index, 1);
        });
    } else {
        ids.forEach(id => {
            if (!selectedOrder.includes(id)) selectedOrder.push(id);
        });
    }
    
    refreshMarkerStyles();
    updateSelectionUI();
}

function removeSelectedId(id) {
    const index = selectedOrder.indexOf(String(id));
    if (index !== -1) selectedOrder.splice(index, 1);
    refreshMarkerStyles();
    updateSelectionUI();
}

function clearSelection() {
    selectedOrder = [];
    editingRouteId = null;
    document.getElementById('route-input').value = '';
    document.getElementById('route-cost-input').value = '';
    document.getElementById('route-info-container').innerHTML = '';
    document.querySelectorAll('.route-item').forEach(el => el.classList.remove('active'));
    document.getElementById('route-edit-status').innerHTML = `<span style="color:#28a745;">${UI.routeCreate}</span>`;
    const saveBtn = document.getElementById('saveRouteBtn');
    const editBtn = document.getElementById('editRouteDataBtn');
    saveBtn.textContent = UI.routeSave;
    saveBtn.classList.remove('is-editing');
    if (editBtn) editBtn.disabled = true;
    if (multiRoute) { map.geoObjects.remove(multiRoute); multiRoute = null; }
    refreshMarkerStyles();
    updateSelectionUI();
}

function openSelectedRouteEditor() {
    const activeRouteEl = document.querySelector('.route-item.active[data-route-editable="1"]');
    if (!activeRouteEl) {
        alert(UI.msgSelectRouteToEdit);
        return;
    }
    const routeId = Number(activeRouteEl.dataset.routeId || 0);
    const source = String(activeRouteEl.dataset.routeSource || '').trim();
    if (routeId > 0 && source) {
        openFlightEditModal(routeId, source);
    }
}

function togglePopups(disabled) {
    popupsDisabled = disabled;
    placemarks.forEach(pm => { if (disabled) pm.balloon.close(); });
    trackerPlacemarks.forEach(pm => { if (disabled) pm.balloon.close(); });
}

function updateMap() {
    const visibleByCoord = {};
    placemarks.forEach((pm, idx) => {
        const g = pm.properties.get('groupData');
        let visible = true;
        if (g.in_flight) {
            const filterVal = flightStatusFilters[g.flight_status];
            if (filterVal === false) visible = false;
        } else {
            // "Доступно к вывозу" must gate all requests outside routes.
            const defaultLayerVisible = customLayerFilters.default !== false;
            if (!defaultLayerVisible) {
                visible = false;
            } else {
                const layer = g.custom_layer || 'default';
                const layerVisible = customLayerFilters[layer];
                if (layerVisible === false) visible = false;
            }
        }
        if (!visible) { pm.options.set('visible', false); return; }
        const key = `${g.mno_sh}|${g.mno_d}`;
        if (!visibleByCoord[key]) visibleByCoord[key] = [];
        visibleByCoord[key].push({ pm, idx });
    });
    for (const key in visibleByCoord) {
        const items = visibleByCoord[key];
        items.sort((a, b) => (groupsData[a.idx].base_index || 0) - (groupsData[b.idx].base_index || 0));
        items.forEach((item, pos) => {
            const pm = item.pm;
            const g = groupsData[item.idx];
            const isFirst = pos === 0;
            const shiftY = pos * 25;
            const isSelected = g.points.some(p => selectedOrder.includes(String(p.zayavka_id)));
            pm.properties.set('hideTriangle', !isFirst);
            pm.options.set({
                iconImageHref: createMarkerSVG(g.total_mass, g.in_flight, g.marker_color, g.flight_id, !isFirst, isSelected),
                iconImageOffset: [-25, -45 - shiftY],
                balloonOffset: [0, -30 - shiftY],
                visible: true
            });
        });
    }
    updateSelectionUI();
}

function filterByFlightStatus(status, visible) { flightStatusFilters[status] = visible; updateMap(); }
function filterByCustomLayer(layerName, visible) { customLayerFilters[layerName] = visible; updateMap(); }

function toDateValue(dateValue) {
    if (!dateValue) return '';
    const date = new Date(String(dateValue).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function formatCardDate(dateValue) {
    const normalized = toDateValue(dateValue);
    if (!normalized) return null;
    const parts = normalized.split('-');
    if (parts.length !== 3) return null;
    return {
        year: parts[0],
        month: parts[1],
        day: parts[2]
    };
}

function buildRouteCardDateLabel(route) {
    if (!route || typeof route !== 'object') return '';
    const status = String(route.status || '');
    const isStarted = status === 'started';
    const rawFrom = isStarted ? route.actual_start_date : route.planned_start_date_from;
    const rawTo = isStarted ? route.actual_end_date : route.planned_start_date_to;
    const from = formatCardDate(rawFrom);
    const to = formatCardDate(rawTo);

    if (!from && !to) return '';
    if (from && !to) return `${from.day}.${from.month}`;
    if (!from && to) return `${to.day}.${to.month}`;
    if (from.day === to.day && from.month === to.month && from.year === to.year) {
        return `${from.day}.${from.month}`;
    }
    if (from.month === to.month && from.year === to.year) {
        return `${from.day}-${to.day}.${from.month}`;
    }
    return `${from.day}.${from.month}-${to.day}.${to.month}`;
}

function buildRouteCardDriverLine(route, driverLabel) {
    const dateLabel = buildRouteCardDateLabel(route);
    const safeDriverLabel = String(driverLabel || UI.driverMissing).trim() || UI.driverMissing;
    const escapedDriver = escapeHtml(safeDriverLabel);
    if (!dateLabel) return escapedDriver;
    return `<span class="route-date-strong">${escapeHtml(dateLabel)}</span>${UI.bullet}${escapedDriver}`;
}

function getRouteMetaById(routeId, source) {
    const idKey = String(routeId || '');
    if (!idKey) return null;
    if (source === 'planned') {
        return (routeCardsMeta && routeCardsMeta[idKey]) ? routeCardsMeta[idKey] : null;
    }
    if (source === 'found') {
        return foundRoutesById[idKey] || null;
    }
    if (source === 'started') {
        return startedRoutesById[idKey] || null;
    }
    return (routeCardsMeta && routeCardsMeta[idKey]) || foundRoutesById[idKey] || startedRoutesById[idKey] || null;
}

async function fetchRouteEditData(routeId) {
    const response = await fetch(`map_files/get_route_edit_data.php?id=${encodeURIComponent(String(routeId || ''))}`, {
        method: 'GET',
        credentials: 'same-origin'
    });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    const data = await response.json();
    if (!data || !data.success || !data.route || typeof data.route !== 'object') {
        throw new Error((data && data.error) ? data.error : UI.msgRouteEditLoadFreshFailed);
    }
    return data.route;
}

function normalizeRouteTitleCandidate(raw) {
    const value = String(raw || '').trim();
    if (!value) return '';
    if (/^\u0420\u0435\u0439\u0441\s*#\d+$/i.test(value)) return '';
    return value;
}

function extractCityFromAddress(address) {
    const value = String(address || '');
    const match = value.match(/(?:^|,\s*)\u0433\.?\s*([^,]+)/iu);
    return (match && match[1]) ? match[1].trim() : '';
}

function buildRouteTitleFromGroups(route) {
    const routeId = Number(route && route.id ? route.id : 0);
    if (!routeId || !Array.isArray(groupsData) || groupsData.length === 0) return '';

    let totalMass = 0;
    let city = '';
    groupsData.forEach(group => {
        if (!group || Number(group.flight_id || 0) !== routeId) return;
        totalMass += Number(group.total_mass || 0);
        if (!city && Array.isArray(group.points) && group.points.length > 0) {
            city = extractCityFromAddress(group.points[0].mno_adres_pogruzki || '');
        }
    });

    if (!city && totalMass <= 0) return '';
    const tons = totalMass > 0 ? totalMass.toFixed(1).replace(/\.0$/, '') : '';
    if (city && tons) return `${city} ${tons}\u0442\u043d`;
    if (city) return city;
    return tons ? `${tons}\u0442\u043d` : '';
}

function resolveRouteTitle(route) {
    if (!route || typeof route !== 'object') return '';
    const candidates = [
        normalizeRouteTitleCandidate(route.route_title),
        normalizeRouteTitleCandidate(route.normal_name),
        normalizeRouteTitleCandidate(route.name),
        normalizeRouteTitleCandidate(route.route_name),
        normalizeRouteTitleCandidate(route.title),
        normalizeRouteTitleCandidate(route.comment),
        normalizeRouteTitleCandidate(route.direction),
        buildRouteTitleFromGroups(route)
    ];
    for (const raw of candidates) {
        const value = String(raw || '').trim();
        if (value !== '') return value;
    }
    return '';
}

function closeFlightEditModal() {
    const modal = document.getElementById('flightEditModal');
    if (modal) modal.style.display = 'none';
    currentEditingMeta = null;
    const preview = document.getElementById('flightChangePreview');
    if (preview) {
        preview.style.display = 'none';
        preview.innerHTML = '';
    }
}

function closeStartConfirmModal() {
    const modal = document.getElementById('startConfirmModal');
    if (modal) modal.style.display = 'none';
}

function clearFlightValidationErrors() {
    const wrap = document.getElementById('flightValidationErrors');
    if (wrap) {
        wrap.style.display = 'none';
        wrap.innerHTML = '';
    }
    ['edit_comment', 'edit_driver_id', 'edit_planned_start_date_from', 'edit_planned_start_date_to', 'edit_actual_start_date', 'edit_actual_end_date', 'edit_cost', 'edit_zayavki_ids', 'edit_route_type', 'edit_source_warehouse_id', 'edit_destination_warehouse_id', 'edit_legal_entity']
        .forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.classList.remove('field-error');
        });
    const driverInput = document.getElementById('edit_driver_input');
    if (driverInput) driverInput.classList.remove('field-error');
    const driverError = document.getElementById('edit_driver_error');
    if (driverError) {
        driverError.style.display = 'none';
        driverError.textContent = '';
    }
}

function showFlightValidationErrors(errorMap, headerText = UI.msgTransitionValidationHeader) {
    const wrap = document.getElementById('flightValidationErrors');
    const orderedFields = ['comment', 'legal_entity_code', 'driver_id', 'planned_start_date_from', 'planned_start_date_to', 'actual_start_date', 'actual_end_date', 'cost', 'zayavki_ids', 'route_type', 'source_warehouse_id', 'destination_warehouse_id'];
    const fieldToInput = {
        comment: 'edit_comment',
        legal_entity_code: 'edit_legal_entity',
        driver_id: 'edit_driver_input',
        planned_start_date_from: 'edit_planned_start_date_from',
        planned_start_date_to: 'edit_planned_start_date_to',
        actual_start_date: 'edit_actual_start_date',
        actual_end_date: 'edit_actual_end_date',
        cost: 'edit_cost',
        zayavki_ids: 'edit_zayavki_ids',
        route_type: 'edit_route_type',
        source_warehouse_id: 'edit_source_warehouse_id',
        destination_warehouse_id: 'edit_destination_warehouse_id'
    };
    const fieldLabels = {
        comment: UI.msgTransitionValidationTitle,
        legal_entity_code: 'Юридическое лицо',
        driver_id: UI.msgTransitionValidationDriver,
        planned_start_date_from: UI.msgTransitionValidationDates,
        planned_start_date_to: UI.msgTransitionValidationDates,
        actual_start_date: UI.msgTransitionValidationDates,
        actual_end_date: UI.msgTransitionValidationDates,
        cost: UI.msgTransitionValidationCost,
        zayavki_ids: UI.msgTransitionValidationRequests,
        route_type: UI.msgTransitionValidationRouteType,
        source_warehouse_id: UI.msgTransitionValidationWarehouseSource,
        destination_warehouse_id: UI.msgTransitionValidationWarehouseDestination
    };

    clearFlightValidationErrors();
    const labels = [];
    orderedFields.forEach((field) => {
        if (!errorMap[field]) return;
        const inputId = fieldToInput[field];
        const el = document.getElementById(inputId);
        if (el) el.classList.add('field-error');
        const label = fieldLabels[field];
        if (label && !labels.includes(label)) labels.push(label);
    });
    if (errorMap.driver_id) {
        const driverError = document.getElementById('edit_driver_error');
        if (driverError) {
            driverError.style.display = 'block';
            driverError.textContent = UI.msgTransitionValidationDriver;
        }
    }

    if (wrap && labels.length > 0) {
        wrap.style.display = 'block';
        wrap.innerHTML = `<div>${headerText}</div><ul>${labels.map(label => `<li>${label}</li>`).join('')}</ul>`;
    }

    const firstField = orderedFields.find(f => errorMap[f]);
    if (firstField) {
        const firstInput = document.getElementById(fieldToInput[firstField]);
        if (firstInput) {
            firstInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInput.focus();
        }
    }
}

function validateRequiredForStrictTransition() {
    const errors = {};
    const driverId = String(document.getElementById('edit_driver_id')?.value || '').trim();
    const fromVal = String(document.getElementById('edit_planned_start_date_from')?.value || '').trim();
    const costVal = String(document.getElementById('edit_cost')?.value || '').trim();
    const routeTypeRaw = String(document.getElementById('edit_route_type')?.value || '').trim();
    const routeType = normalizeRouteType(routeTypeRaw, currentEditingMeta?.unload_type || 'OO');
    const sourceWarehouseId = String(document.getElementById('edit_source_warehouse_id')?.value || '').trim();
    const destinationWarehouseId = String(document.getElementById('edit_destination_warehouse_id')?.value || '').trim();
    const ids = String(document.getElementById('edit_zayavki_ids')?.value || '')
        .split(',')
        .map(v => v.trim())
        .filter(v => /^\d+$/.test(v));

    if (!driverId) errors.driver_id = true;
    if (!document.querySelector('input[name="edit_legal_entity_code"]:checked')) errors.legal_entity_code = true;
    if (!fromVal) errors.planned_start_date_from = true;
    if (!costVal) errors.cost = true;
    if (ids.length === 0) errors.zayavki_ids = true;
    if (!routeTypeRaw) errors.route_type = true;
    if (routeType === 'generator_to_warehouse' && !destinationWarehouseId) {
        errors.destination_warehouse_id = true;
    }
    if (routeType === 'warehouse_to_warehouse') {
        if (!sourceWarehouseId) errors.source_warehouse_id = true;
        if (!destinationWarehouseId) errors.destination_warehouse_id = true;
        if (sourceWarehouseId && destinationWarehouseId && sourceWarehouseId === destinationWarehouseId) {
            errors.source_warehouse_id = true;
            errors.destination_warehouse_id = true;
        }
    }
    if (routeType === 'warehouse_to_utilizer' && !sourceWarehouseId) {
        errors.source_warehouse_id = true;
    }
    return errors;
}

function buildWarehouseOptions(selectedId, placeholderText) {
    const options = [`<option value="">${escapeHtml(placeholderText || 'Выберите склад')}</option>`];
    const selectedStr = String(selectedId || '').trim();
    (Array.isArray(warehousesData) ? warehousesData : []).forEach((warehouse) => {
        if (!warehouse || !warehouse.id) return;
        const idStr = String(warehouse.id);
        const selected = idStr === selectedStr ? ' selected' : '';
        const title = String(warehouse.name || (`Склад #${idStr}`));
        options.push(`<option value="${idStr}"${selected}>${escapeHtml(title)}</option>`);
    });
    return options.join('');
}

function openWarehouseCreateModal(targetFieldId) {
    warehouseCreateTargetFieldId = String(targetFieldId || '').trim();
    const modal = document.getElementById('warehouseCreateModal');
    if (!modal) return;
    const errors = document.getElementById('warehouseCreateErrors');
    const nameInput = document.getElementById('new_warehouse_name');
    const addressInput = document.getElementById('new_warehouse_address');
    const latInput = document.getElementById('new_warehouse_latitude');
    const lonInput = document.getElementById('new_warehouse_longitude');
    const note = document.getElementById('warehouseGeocodeNote');
    if (errors) {
        errors.style.display = 'none';
        errors.innerHTML = '';
    }
    if (note) {
        note.style.display = 'none';
        note.textContent = '';
    }
    if (nameInput) nameInput.value = '';
    if (addressInput) addressInput.value = '';
    if (latInput) latInput.value = '';
    if (lonInput) lonInput.value = '';
    warehouseAddressGeocoded = false;
    modal.style.display = 'flex';
    if (nameInput) setTimeout(() => nameInput.focus(), 0);
}

function closeWarehouseCreateModal() {
    const modal = document.getElementById('warehouseCreateModal');
    if (modal) modal.style.display = 'none';
}

async function saveWarehouseFromModal() {
    const errors = document.getElementById('warehouseCreateErrors');
    const nameInput = document.getElementById('new_warehouse_name');
    const addressInput = document.getElementById('new_warehouse_address');
    const latInput = document.getElementById('new_warehouse_latitude');
    const lonInput = document.getElementById('new_warehouse_longitude');
    const saveBtn = document.getElementById('warehouseCreateSaveBtn');

    const name = String(nameInput?.value || '').trim();
    const fullAddress = String(addressInput?.value || '').trim();
    const latitudeRaw = String(latInput?.value || '').trim();
    const longitudeRaw = String(lonInput?.value || '').trim();

    if (errors) {
        errors.style.display = 'none';
        errors.innerHTML = '';
    }

    if (!name || !fullAddress) {
        if (errors) {
            errors.style.display = 'block';
            errors.textContent = UI.msgWarehouseRequired;
        }
        return;
    }

    const payload = {
        name,
        full_address: fullAddress,
        latitude: latitudeRaw === '' ? null : Number(latitudeRaw),
        longitude: longitudeRaw === '' ? null : Number(longitudeRaw)
    };

    if (payload.latitude !== null && !Number.isFinite(payload.latitude)) {
        if (errors) {
            errors.style.display = 'block';
            errors.textContent = UI.msgWarehouseLatInvalid;
        }
        return;
    }
    if (payload.longitude !== null && !Number.isFinite(payload.longitude)) {
        if (errors) {
            errors.style.display = 'block';
            errors.textContent = UI.msgWarehouseLonInvalid;
        }
        return;
    }

    const originalText = saveBtn ? saveBtn.textContent : '';
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = UI.msgSaving;
    }

    try {
        const response = await fetch('map_files/save_warehouse.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok || !data || !data.success || !data.warehouse || !data.warehouse.id) {
            if (errors) {
                errors.style.display = 'block';
                errors.textContent = (data && data.message) ? data.message : UI.msgWarehouseSaveFailed;
            }
            return;
        }

        const newWarehouseId = String(data.warehouse.id);
        await loadWarehouses();
        const sourceSelect = document.getElementById('edit_source_warehouse_id');
        const destinationSelect = document.getElementById('edit_destination_warehouse_id');
        const currentSource = sourceSelect ? sourceSelect.value : '';
        const currentDestination = destinationSelect ? destinationSelect.value : '';
        if (sourceSelect) {
            sourceSelect.innerHTML = buildWarehouseOptions(currentSource, 'Выберите склад отправления');
        }
        if (destinationSelect) {
            destinationSelect.innerHTML = buildWarehouseOptions(currentDestination, 'Выберите склад назначения');
        }
        const targetSelect = document.getElementById(warehouseCreateTargetFieldId);
        if (targetSelect) {
            targetSelect.value = newWarehouseId;
            targetSelect.dispatchEvent(new Event('input', { bubbles: true }));
            targetSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
        closeWarehouseCreateModal();
    } catch (e) {
        if (errors) {
            errors.style.display = 'block';
            errors.textContent = UI.msgWarehouseSaveNetwork;
        }
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = originalText;
        }
    }
}

async function geocodeWarehouseAddress() {
    const errors = document.getElementById('warehouseCreateErrors');
    const note = document.getElementById('warehouseGeocodeNote');
    const addressInput = document.getElementById('new_warehouse_address');
    const latInput = document.getElementById('new_warehouse_latitude');
    const lonInput = document.getElementById('new_warehouse_longitude');
    const geocodeBtn = document.getElementById('warehouseGeocodeBtn');
    const address = String(addressInput?.value || '').trim();

    if (errors) {
        errors.style.display = 'none';
        errors.innerHTML = '';
    }
    if (note) {
        note.style.display = 'none';
        note.textContent = '';
    }

    if (!address) {
        if (errors) {
            errors.style.display = 'block';
            errors.textContent = UI.msgWarehouseGeocodeAddressRequired;
        }
        if (addressInput) addressInput.focus();
        return;
    }

    const prevText = geocodeBtn ? geocodeBtn.textContent : '';
    if (geocodeBtn) {
        geocodeBtn.disabled = true;
        geocodeBtn.textContent = UI.msgWarehouseGeocodeLoading;
    }

    try {
        const response = await fetch('map_files/geocode_address.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ address })
        });
        const data = await response.json();
        if (!response.ok || !data || !data.success) {
            if (errors) {
                errors.style.display = 'block';
                errors.textContent = (data && data.error) ? data.error : 'Не удалось определить координаты.';
            }
            return;
        }
        if (addressInput) addressInput.value = String(data.address || address);
        if (latInput) latInput.value = String(data.latitude ?? '');
        if (lonInput) lonInput.value = String(data.longitude ?? '');
        warehouseAddressGeocoded = true;
        if (note) {
            note.style.display = 'block';
            note.textContent = UI.msgWarehouseGeocodeSuccess;
        }
    } catch (e) {
        if (errors) {
            errors.style.display = 'block';
            errors.textContent = UI.msgWarehouseGeocodeNetwork;
        }
    } finally {
        if (geocodeBtn) {
            geocodeBtn.disabled = false;
            geocodeBtn.textContent = prevText;
        }
    }
}

function syncRouteTypeFieldsVisibility(routeTypeRaw) {
    const routeType = normalizeRouteType(routeTypeRaw, 'OO');
    const sourceWrap = document.getElementById('edit_source_warehouse_wrap');
    const destWrap = document.getElementById('edit_destination_warehouse_wrap');
    const warehouseGroup = document.getElementById('edit_warehouse_group');
    const routeTypeInput = document.getElementById('edit_route_type');
    const sourceWarehouseInput = document.getElementById('edit_source_warehouse_id');
    const destinationWarehouseInput = document.getElementById('edit_destination_warehouse_id');

    const showSource = routeType === 'warehouse_to_warehouse' || routeType === 'warehouse_to_utilizer';
    const showDest = routeType === 'generator_to_warehouse' || routeType === 'warehouse_to_warehouse';
    if (sourceWrap) sourceWrap.style.display = showSource ? 'block' : 'none';
    if (destWrap) destWrap.style.display = showDest ? 'block' : 'none';
    if (warehouseGroup) {
        const hasVisibleFields = showSource || showDest;
        warehouseGroup.style.display = hasVisibleFields ? 'grid' : 'none';
        warehouseGroup.classList.toggle('is-visible', hasVisibleFields);
    }
}

async function loadDriversCatalog() {
    try {
        const response = await fetch('map_files/get_drivers.php', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        if (!response.ok || !data || !data.success || !Array.isArray(data.drivers)) {
            return false;
        }
        currentDriversCatalog = data.drivers;
        return true;
    } catch (e) {
        return false;
    }
}

function applyLatinToCyrillicPlate(value) {
    const map = {
        A: '\u0410', B: '\u0412', C: '\u0421', E: '\u0415', H: '\u041d',
        K: '\u041a', M: '\u041c', O: '\u041e', P: '\u0420', T: '\u0422',
        X: '\u0425', Y: '\u0423'
    };
    const upper = String(value || '').toUpperCase();
    return upper.replace(/[ABCEHKMOPTXY]/g, (ch) => map[ch] || ch);
}

function normalizeDriverNameInput(value) {
    const cleaned = String(value || '').replace(/\s{2,}/g, ' ');
    return cleaned;
}

function normalizeDriverNameFinal(value) {
    const cleaned = String(value || '')
        .replace(/[^\u0410-\u042F\u0430-\u044F\u0401\u0451\s-]/g, ' ')
        .replace(/\s+/g, ' ');
    const endsWithSpace = /\s$/.test(cleaned);
    const trimmed = cleaned.trim();
    if (!trimmed) return endsWithSpace ? ' ' : '';
    const normalized = trimmed
        .split(' ')
        .filter(Boolean)
        .map((part) => {
            return part
                .split('-')
                .filter(Boolean)
                .map((seg) => {
                    const lower = seg.toLowerCase();
                    return lower.charAt(0).toUpperCase() + lower.slice(1);
                })
                .join('-');
        })
        .join(' ');
    return endsWithSpace ? `${normalized} ` : normalized;
}

function normalizeDriverPlateInput(value) {
    const upper = applyLatinToCyrillicPlate(String(value || '').toUpperCase());
    return upper.replace(/[^\u0410-\u042F0-9]/g, '').slice(0, 9);
}

function isValidDriverFullName(value) {
    const normalized = String(value || '').trim();
    const words = normalized.split(/\s+/).filter(Boolean);
    if (words.length !== 3) return false;
    return words.every((word) => /^[\u0410-\u042F\u0401][\u0430-\u044F\u0451-]+$/.test(word) && word.replace(/-/g, '').length >= 2);
}

function isValidDriverPlate(value) {
    return /^[\u0410\u0412\u0415\u041A\u041C\u041D\u041E\u0420\u0421\u0422\u0423\u0425]\d{3}[\u0410\u0412\u0415\u041A\u041C\u041D\u041E\u0420\u0421\u0422\u0423\u0425]{2}\d{2,3}$/.test(String(value || ''));
}

function fillDriverSelect(driverSelect, selectedDriverId) {
    if (!driverSelect) return;
    const selected = String(selectedDriverId || '');
    const options = [`<option value="">${UI.chooseDriver}</option>`];
    if (Array.isArray(currentDriversCatalog)) {
        currentDriversCatalog.forEach(driver => {
            if (!driver || !driver.id) return;
            const val = String(driver.id);
            const labelText = String(driver.label || (UI.driverPrefix + val));
            const isSelected = val === selected ? ' selected' : '';
            options.push(`<option value="${val}"${isSelected}>${escapeHtml(labelText)}</option>`);
        });
    }
    driverSelect.innerHTML = options.join('');
}

function getDriverById(driverId) {
    const id = String(driverId || '');
    if (!id) return null;
    return Array.isArray(currentDriversCatalog) ? currentDriversCatalog.find((d) => String(d?.id || '') === id) || null : null;
}

function renderDriverMenu(query) {
    const menu = document.getElementById('edit_driver_menu');
    if (!menu) return;
    const q = String(query || '').trim().toLowerCase();
    const matched = (Array.isArray(currentDriversCatalog) ? currentDriversCatalog : []).filter((driver) => {
        if (!driver || !driver.id) return false;
        if (!q) return true;
        const haystack = `${driver.full_name || ''} ${driver.vehicle_make_plate || ''} ${driver.label || ''}`.toLowerCase();
        return haystack.includes(q);
    });
    driverMenuItems = matched;
    if (!matched.length) {
        menu.innerHTML = `<div class="driver-combobox-item">${escapeHtml(UI.msgDriverNotFound)}</div>`;
        return;
    }
    menu.innerHTML = matched.map((driver, idx) =>
        `<div class="driver-combobox-item${idx === 0 ? ' is-active' : ''}" data-driver-id="${escapeHtml(String(driver.id))}">${escapeHtml(String(driver.label || ''))}</div>`
    ).join('');
}

function showDriverMenu() {
    const menu = document.getElementById('edit_driver_menu');
    const input = document.getElementById('edit_driver_input');
    if (!menu || !input) return;
    renderDriverMenu(input.value);
    menu.style.display = 'block';
    driverMenuVisible = true;
}

function hideDriverMenu() {
    const menu = document.getElementById('edit_driver_menu');
    if (!menu) return;
    menu.style.display = 'none';
    driverMenuVisible = false;
}

function selectDriverValue(driverId, updateInput = true) {
    const driverSelect = document.getElementById('edit_driver_id');
    if (driverSelect) driverSelect.value = String(driverId || '');
    if (updateInput) {
        const input = document.getElementById('edit_driver_input');
        const driver = getDriverById(driverId);
        if (input) input.value = driver ? String(driver.label || '') : '';
    }
    const error = document.getElementById('edit_driver_error');
    if (error) {
        error.style.display = 'none';
        error.textContent = '';
    }
}

function validateDriverComboboxSelection() {
    const input = document.getElementById('edit_driver_input');
    const select = document.getElementById('edit_driver_id');
    const error = document.getElementById('edit_driver_error');
    if (!input || !select) return true;
    const typed = String(input.value || '').trim();
    const selectedId = String(select.value || '').trim();
    if (!typed) {
        select.value = '';
        if (error) {
            error.style.display = 'none';
            error.textContent = '';
        }
        return true;
    }
    if (selectedId) return true;
    if (error) {
        error.style.display = 'block';
        error.textContent = UI.msgDriverMustPickFromList;
    }
    return false;
}

function setDriverCreateError(message) {
    setDriverCreateResult(String(message || '').trim(), true);
}

function setInlineFieldError(id, message) {
    const el = document.getElementById(id);
    if (!el) return;
    const text = String(message || '').trim();
    if (!text) {
        el.style.display = 'none';
        el.textContent = '';
        return;
    }
    el.style.display = 'block';
    el.textContent = text;
}

function setDriverCreateResult(message, isError) {
    const el = document.getElementById('driverCreateResult');
    if (!el) return;
    const text = String(message || '').trim();
    if (!text) {
        el.style.display = 'none';
        el.textContent = '';
        el.style.background = '';
        el.style.borderColor = '';
        el.style.color = '';
        return;
    }
    el.style.display = 'block';
    el.textContent = text;
    if (isError) {
        el.style.background = '#fff4f4';
        el.style.borderColor = '#efb0b0';
        el.style.color = '#842029';
    } else {
        el.style.background = '#eef7ef';
        el.style.borderColor = '#cfe7d1';
        el.style.color = '#1f5f29';
    }
}

function syncDriverCreateGpsType() {
    const selected = document.querySelector('input[name="new_driver_gps_type"]:checked');
    const type = String(selected?.value || '');
    const note = document.getElementById('new_driver_gps_note');
    if (note) {
        note.textContent = type === 'retranslation'
            ? UI.msgDriverGpsHelpRetranslation
            : UI.msgDriverGpsHelpNewMobile;
    }
}

function openDriverCreateModal() {
    const modal = document.getElementById('driverCreateModal');
    if (!modal) return;
    const nameInput = document.getElementById('new_driver_full_name');
    const plateInput = document.getElementById('new_driver_vehicle_number');
    const gpsTypeInputs = Array.from(document.querySelectorAll('input[name="new_driver_gps_type"]'));
    const checkResult = document.getElementById('driverCheckResult');
    if (nameInput) nameInput.value = '';
    if (plateInput) plateInput.value = '';
    gpsTypeInputs.forEach((el) => { el.checked = false; });
    if (checkResult) {
        checkResult.style.display = 'none';
        checkResult.textContent = '';
    }
    setDriverCreateError('');
    setDriverCreateResult('', false);
    setInlineFieldError('new_driver_full_name_error', '');
    setInlineFieldError('new_driver_vehicle_number_error', '');
    setInlineFieldError('new_driver_gps_type_error', '');
    syncDriverCreateGpsType();
    modal.style.display = 'flex';
}

function closeDriverCreateModal() {
    const modal = document.getElementById('driverCreateModal');
    if (modal) modal.style.display = 'none';
    driverCreateInFlight = false;
}

async function saveDriverFromModal() {
    if (driverCreateInFlight) return;
    const saveBtn = document.getElementById('driverCreateSaveBtn');
    const prevText = saveBtn ? saveBtn.textContent : '';
    const fullNameInput = document.getElementById('new_driver_full_name');
    const plateInput = document.getElementById('new_driver_vehicle_number');
    const gpsTypeSelected = document.querySelector('input[name="new_driver_gps_type"]:checked');

    const fullName = String(normalizeDriverNameFinal(fullNameInput?.value || '')).trim();
    const vehicleMakePlate = normalizeDriverPlateInput(plateInput?.value || '');
    const gpsType = String(gpsTypeSelected?.value || '');

    if (fullNameInput) fullNameInput.value = fullName;
    if (plateInput) plateInput.value = vehicleMakePlate;
    const nameOk = isValidDriverFullName(fullName);
    const plateOk = isValidDriverPlate(vehicleMakePlate);
    const gpsTypeOk = !!gpsType;
    setInlineFieldError('new_driver_full_name_error', nameOk ? '' : UI.msgDriverNameInvalid);
    setInlineFieldError('new_driver_vehicle_number_error', plateOk ? '' : UI.msgDriverPlateInvalid);
    setInlineFieldError('new_driver_gps_type_error', gpsTypeOk ? '' : UI.msgDriverGpsTypeRequired);
    if (!nameOk || !plateOk || !gpsTypeOk) {
        setDriverCreateError('');
        return;
    }

    setDriverCreateError('');
    setDriverCreateResult('', false);
    driverCreateInFlight = true;
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = UI.msgDriverCreateInProgress;
    }
    try {
        const response = await fetch('map_files/save_driver.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'create_driver',
                full_name: fullName,
                vehicle_make_plate: vehicleMakePlate,
                gps_connection_type: gpsType
            })
        });
        const data = await response.json();
        if (!response.ok || !data || !data.success) {
            setDriverCreateError(data?.message || UI.msgDriverCreateFailed);
            return;
        }
        const driver = data.driver && data.driver.id ? data.driver : null;
        if (!driver) {
            setDriverCreateError(UI.msgDriverCreateServerNoData);
            return;
        }
        await loadDriversCatalog();
        fillDriverSelect(document.getElementById('edit_driver_id'), String(driver.id));
        selectDriverValue(String(driver.id), true);
        const driverSelect = document.getElementById('edit_driver_id');
        if (driverSelect) driverSelect.value = String(driver.id);
        updateFlightModalSummary();

        if (data.existing) {
            setDriverCreateResult(UI.msgDriverCreateExisting, false);
        } else if (gpsType === 'new_mobile_tracker') {
            const info = [
                UI.msgDriverCreateNewTrackerDone,
                `Водитель: ${data.tracker_name || vehicleMakePlate}`,
                `UniqueID: ${data.tracker_uniqueid || '-'}`,
                `${UI.msgDriverCreateFreeLeft}: ${data.free_trackers_remaining ?? '-'}`,
            ].join('\n');
            setDriverCreateResult(info, false);
        } else {
            setDriverCreateResult(UI.msgDriverCreateRetranslationDone, false);
        }
        if (gpsType === 'retranslation' && !data.existing) {
            setDriverCreateResult(UI.msgDriverCreateRetranslationDone, false);
        }
        closeDriverCreateModal();
    } catch (error) {
        setDriverCreateError(UI.msgDriverCreateNetworkError);
    } finally {
        driverCreateInFlight = false;
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = prevText || uiText('driver.create.button', 'Создать водителя');
        }
    }
}

function setDriverTrackerCheckMessage(message, isError = false) {
    return;
}

async function openFlightEditModal(routeId, source) {
    const modal = document.getElementById('flightEditModal');
    if (!modal) return;

    const baseMeta = getRouteMetaById(routeId, source);
    if (!baseMeta) {
        alert(UI.msgFlightDataNotFound);
        return;
    }
    let freshMeta;
    try {
        freshMeta = await fetchRouteEditData(routeId);
    } catch (error) {
        console.error('openFlightEditModal fresh load failed:', error);
        alert(UI.msgRouteEditLoadFreshFailed);
        return;
    }
    const meta = { ...baseMeta, ...freshMeta };

    const titleEl = document.getElementById('flightEditTitle');
    const idInput = document.getElementById('edit_flight_id');
    const sourceInput = document.getElementById('edit_flight_source');
    const fromInput = document.getElementById('edit_planned_start_date_from');
    const toInput = document.getElementById('edit_planned_start_date_to');
    const actualFromInput = document.getElementById('edit_actual_start_date');
    const actualToInput = document.getElementById('edit_actual_end_date');
    const plannedDateRangeWrap = document.getElementById('plannedDateRangeWrap');
    const actualDateRangeWrap = document.getElementById('actualDateRangeWrap');
    const plannedDateRangeTitle = document.getElementById('plannedDateRangeTitle');
    const actualDateRangeTitle = document.getElementById('actualDateRangeTitle');
    const driverSelect = document.getElementById('edit_driver_id');
    const costInput = document.getElementById('edit_cost');
    const commentInput = document.getElementById('edit_comment');
    const zayavkiInput = document.getElementById('edit_zayavki_ids');
    const routeTypeInput = document.getElementById('edit_route_type');
    const sourceWarehouseInput = document.getElementById('edit_source_warehouse_id');
    const destinationWarehouseInput = document.getElementById('edit_destination_warehouse_id');
    const transferFoundBtn = document.getElementById('flightEditTransferFoundBtn');
    const statusInput = document.getElementById('edit_current_status');

    currentEditingMeta = {
        ...meta,
        id: Number(meta.id || routeId),
        status: String(meta.status || (source === 'found' ? 'found' : (source === 'started' ? 'started' : 'planned_route')))
    };
    const routeTitle = resolveRouteTitle(meta);
    if (titleEl) {
        titleEl.textContent = routeTitle
            ? `${UI.routeEditTitle} #${meta.id || routeId}: ${routeTitle}`
            : `${UI.routeEditTitle} #${meta.id || routeId}`;
        titleEl.classList.remove('flight-title-planned', 'flight-title-found', 'flight-title-started');
        if (currentEditingMeta.status === 'planned_route') {
            titleEl.classList.add('flight-title-planned');
        } else if (currentEditingMeta.status === 'found') {
            titleEl.classList.add('flight-title-found');
        } else if (currentEditingMeta.status === 'started') {
            titleEl.classList.add('flight-title-started');
        }
    }
    if (idInput) idInput.value = String(meta.id || routeId);
    if (sourceInput) sourceInput.value = source === 'found' ? 'found' : (source === 'started' ? 'started' : 'planned');
    if (fromInput) fromInput.value = toDateValue(meta.planned_start_date_from);
    if (toInput) toInput.value = toDateValue(meta.planned_start_date_to);
    if (actualFromInput) actualFromInput.value = toDateValue(meta.actual_start_date);
    if (actualToInput) actualToInput.value = toDateValue(meta.actual_end_date);
    if (costInput) costInput.value = (meta.cost !== null && meta.cost !== undefined) ? String(meta.cost) : '';
    if (commentInput) commentInput.value = String(meta.comment || routeTitle || '');
    if (zayavkiInput) zayavkiInput.value = String(meta.zayavki_ids || '');
    const initialRouteType = normalizeRouteType(meta.route_type || '', meta.unload_type || 'OO');
    if (routeTypeInput) routeTypeInput.value = initialRouteType;
    document.querySelectorAll('input[name="edit_legal_entity_code"]').forEach((input) => {
        input.checked = input.value === String(meta.legal_entity_code || '');
    });
    await Promise.allSettled([loadDriversCatalog(), loadWarehouses(true)]);
    if (sourceWarehouseInput) {
        sourceWarehouseInput.innerHTML = buildWarehouseOptions(meta.source_warehouse_id, 'Выберите склад отправления');
    }
    if (destinationWarehouseInput) {
        destinationWarehouseInput.innerHTML = buildWarehouseOptions(meta.destination_warehouse_id, 'Выберите склад назначения');
    }
    syncRouteTypeFieldsVisibility(initialRouteType);
    if (statusInput) statusInput.value = currentEditingMeta.status;
    if (transferFoundBtn) {
        transferFoundBtn.style.display = source === 'planned' ? 'inline-flex' : 'none';
    }

    const useActualDates = currentEditingMeta.status === 'started';
    if (plannedDateRangeWrap) plannedDateRangeWrap.style.display = useActualDates ? 'none' : 'block';
    if (actualDateRangeWrap) actualDateRangeWrap.style.display = useActualDates ? 'block' : 'none';
    if (plannedDateRangeTitle) plannedDateRangeTitle.textContent = UI.modalPlanStart;
    if (actualDateRangeTitle) actualDateRangeTitle.textContent = UI.modalActualDates;

    fillDriverSelect(driverSelect, meta.driver_id || '');
    selectDriverValue(String(meta.driver_id || ''), true);

    applyLifecycleButtons(currentEditingMeta.status);
    setFlightEditReadOnlyMode(currentEditingMeta.status === 'started');
    syncRequiredFieldLabels(currentEditingMeta.status);
    clearFlightValidationErrors();
    updateFlightModalSummary();
    modal.style.display = 'flex';
}

function applyLifecycleButtons(status) {
    const updateTitle = document.getElementById('workflowUpdateTitle');
    const updateDesc = document.getElementById('workflowUpdateDesc');
    const saveBtn = document.getElementById('flightEditSaveBtn');
    const toFoundDesc = document.querySelector('#workflowToFoundWrap .workflow-desc');
    const toStartedDesc = document.querySelector('#workflowToStartedWrap .workflow-desc');
    const toPlannedDesc = document.querySelector('#workflowBackToPlannedWrap .workflow-desc');
    const toCompletedDesc = document.querySelector('#workflowToCompletedWrap .workflow-desc');
    const toFoundBackDesc = document.querySelector('#workflowBackToFoundWrap .workflow-desc');
    const map = {
        updateSection: document.getElementById('workflowUpdateSection'),
        toFound: document.getElementById('workflowToFoundWrap'),
        toStarted: document.getElementById('workflowToStartedWrap'),
        toPlanned: document.getElementById('workflowBackToPlannedWrap'),
        toFoundBack: document.getElementById('workflowBackToFoundWrap'),
        toCompleted: document.getElementById('workflowToCompletedWrap'),
        startedInfo: document.getElementById('workflowStartedInfoWrap'),
        deleteWrap: document.getElementById('workflowDeleteWrap')
    };
    if (map.updateSection) {
        map.updateSection.classList.remove('is-cyan');
    }
    if (saveBtn) {
        saveBtn.classList.remove('route-edit-btn', 'route-transfer-btn');
        saveBtn.classList.add('route-edit-btn');
    }
    Object.values(map).forEach(el => {
        if (el) el.style.display = 'none';
    });
    if (toFoundDesc) {
        setSafeStaticHtml(toFoundDesc, uiText('route.action.to_found.desc', 'После перевода рейс считается согласованным. Будут зафиксированы водитель, даты, стоимость и заявки. В MAX отправится уведомление, начнётся подготовка транспортных документов.'));
    }
    if (toStartedDesc) {
        setSafeStaticHtml(toStartedDesc, uiText('route.action.to_started.desc', 'Рейс перейдёт в статус «Вывоз начался». Подключается контроль выполнения перевозки и логика трекера. В MAX будет отправлено уведомление.'));
    }
    if (toPlannedDesc) {
        setSafeStaticHtml(toPlannedDesc, uiText('route.action.back_to_planned.desc', 'Рейс будет возвращён в планирование. Подготовку документов нужно проверить или приостановить. В MAX будет отправлено уведомление.'));
    }
    if (toCompletedDesc) {
        setSafeStaticHtml(toCompletedDesc, uiText('route.action.to_completed.desc', 'После завершения рейс будет переведен в архив перевозок.'));
    }
    if (toFoundBackDesc) {
        setSafeStaticHtml(toFoundBackDesc, uiText('route.action.back_to_found.desc', 'Рейс будет возвращён из выполнения в статус «Рейс сформирован». В MAX будет отправлено уведомление.'));
    }
    if (status === 'planned_route') {
        if (updateTitle) setSafeStaticHtml(updateTitle, uiText('route.workflow.update.title', 'Актуализация рейса'));
        if (updateDesc) setSafeStaticHtml(updateDesc, uiText('route.workflow.update.desc', 'Изменения дат автоматически фиксируются в МАКС.'));
        if (saveBtn) saveBtn.textContent = 'Сохранить изменения';
        if (map.updateSection) map.updateSection.style.display = 'block';
        if (map.toFound) map.toFound.style.display = 'block';
        if (map.deleteWrap) map.deleteWrap.style.display = 'block';
    } else if (status === 'found') {
        if (updateTitle) setSafeStaticHtml(updateTitle, uiText('route.workflow.update.title', 'Актуализация рейса'));
        if (updateDesc) setSafeStaticHtml(updateDesc, uiText('route.workflow.update.desc.found', 'Изменения автоматически фиксируются в МАКС.'));
        if (saveBtn) saveBtn.textContent = 'Сохранить изменения';
        if (saveBtn) {
            saveBtn.classList.remove('route-edit-btn');
            saveBtn.classList.add('route-transfer-btn');
        }
        if (map.updateSection) {
            map.updateSection.classList.add('is-cyan');
        }
        if (map.updateSection) map.updateSection.style.display = 'block';
        if (map.toStarted) map.toStarted.style.display = 'block';
        if (map.toPlanned) map.toPlanned.style.display = 'block';
    } else if (status === 'started') {
        if (map.updateSection) map.updateSection.style.display = 'none';
        if (map.startedInfo) map.startedInfo.style.display = 'block';
        if (map.toFoundBack) map.toFoundBack.style.display = 'block';
        if (map.toCompleted) map.toCompleted.style.display = 'block';
    }
}

function setFlightEditReadOnlyMode(isReadOnly) {
    const modal = document.querySelector('#flightEditModal .flight-modal');
    const fieldIds = [
        'edit_comment',
        'edit_driver_id',
        'edit_planned_start_date_from',
        'edit_planned_start_date_to',
        'edit_actual_start_date',
        'edit_actual_end_date',
        'edit_cost',
        'edit_zayavki_ids',
        'edit_route_type',
        'edit_source_warehouse_id',
        'edit_destination_warehouse_id',
        'edit_legal_entity_planex',
        'edit_legal_entity_mg_logistic'
    ];
    fieldIds.forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        if (isReadOnly) {
            el.setAttribute('disabled', 'disabled');
            if (id === 'edit_comment' || id === 'edit_zayavki_ids') {
                el.setAttribute('readonly', 'readonly');
            }
        } else {
            el.removeAttribute('disabled');
            el.removeAttribute('readonly');
        }
    });
    const addDriverBtn = document.getElementById('add_driver_btn');
    if (addDriverBtn) {
        addDriverBtn.disabled = !!isReadOnly;
    }
    const driverInput = document.getElementById('edit_driver_input');
    const driverToggle = document.getElementById('edit_driver_toggle');
    if (driverInput) driverInput.disabled = !!isReadOnly;
    if (driverToggle) driverToggle.disabled = !!isReadOnly;
    if (modal) {
        modal.classList.toggle('is-started-readonly', !!isReadOnly);
    }
}

function setLabelTextForField(fieldId, text) {
    const label = document.querySelector(`label[for="${fieldId}"]`);
    if (label) label.textContent = text;
}

function syncRequiredFieldLabels(status) {
    const isPlanned = status === 'planned_route';
    setLabelTextForField('edit_comment', `\u041a\u043e\u043c\u043c\u0435\u043d\u0442\u0430\u0440\u0438\u0439 / \u0437\u0430\u0433\u043e\u043b\u043e\u0432\u043e\u043a *`);
    setLabelTextForField('edit_zayavki_ids', `\u0421\u043f\u0438\u0441\u043e\u043a \u0437\u0430\u044f\u0432\u043e\u043a *`);
    setLabelTextForField('edit_driver_id', isPlanned ? `${UI.modalDriver} *` : UI.modalDriver);
    setLabelTextForField('edit_cost', isPlanned ? `${UI.modalCost} *` : UI.modalCost);
    const plannedRangeTitle = document.getElementById('plannedDateRangeTitle');
    if (plannedRangeTitle) {
        plannedRangeTitle.textContent = isPlanned ? `${UI.modalPlanStart} *` : UI.modalPlanStart;
    }
}

function applySafeModalText() {
    const labels = {
        edit_planned_start_date_from: UI.modalPlanStart,
        edit_planned_start_date_to: UI.modalPlanEnd,
        edit_actual_start_date: '\u0421',
        edit_actual_end_date: '\u041f\u043e',
        edit_driver_id: UI.modalDriver,
        edit_cost: UI.modalCost,
        start_actual_start_date: UI.modalStartDate
    };
    Object.keys(labels).forEach((id) => {
        const el = document.querySelector(`label[for="${id}"]`);
        if (el) el.textContent = labels[id];
    });

    const editTitle = document.getElementById('flightEditTitle');
    if (editTitle && !String(editTitle.textContent || '').trim()) {
        editTitle.textContent = UI.routeEditTitle;
    }
    const startTitle = document.querySelector('#startConfirmModal .flight-modal-title');
    if (startTitle) startTitle.textContent = UI.modalStartTitle;
    const transitionDateLabel = document.getElementById('transitionConfirmDateLabel');
    if (transitionDateLabel) transitionDateLabel.textContent = UI.modalStartDate;

    const saveBtn = document.getElementById('flightEditSaveBtn');
    const cancelBtn = document.getElementById('flightEditCancelBtn');
    const foundBtn = document.getElementById('flightEditTransferFoundBtn');
    const completedBtn = document.getElementById('flightEditToCompletedBtn');
    const startBtn = document.getElementById('startConfirmBtn');
    const startCancelBtn = document.getElementById('startCancelBtn');
    if (saveBtn) saveBtn.textContent = UI.modalSave;
    if (cancelBtn) cancelBtn.textContent = UI.modalCancel;
    if (foundBtn) foundBtn.textContent = UI.modalToFound;
    if (completedBtn) completedBtn.textContent = UI.labelToCompleted;
    if (startBtn) startBtn.textContent = UI.modalToStarted;
    if (startCancelBtn) startCancelBtn.textContent = UI.modalCancel;
}

function openStartConfirmModal(routeId, targetStatus = 'started') {
    const modal = document.getElementById('startConfirmModal');
    if (!modal) return;
    const idInput = document.getElementById('start_flight_id');
    const targetInput = document.getElementById('start_target_status');
    const dateInput = document.getElementById('start_actual_start_date');
    const dateLabel = document.getElementById('transitionConfirmDateLabel');
    const titleEl = document.getElementById('transitionConfirmTitle');
    const confirmBtn = document.getElementById('startConfirmBtn');
    if (idInput) idInput.value = String(routeId || '');
    if (targetInput) targetInput.value = targetStatus;
    if (dateInput) {
        if (targetStatus === 'started') {
            dateInput.value = '';
        } else {
            const metaForDate = getRouteMetaById(routeId, 'started') || {};
            const preferred = metaForDate.actual_end_date || '';
            dateInput.value = toDateValue(preferred) || '';
        }
    }
    const preview = document.getElementById('transitionConfirmPreview');
    const meta = getRouteMetaById(routeId, targetStatus === 'started' ? 'found' : 'started') || getRouteMetaById(routeId, 'planned') || {};
    // Build context from current form for fresh data (avoid stale meta)
    const previewPayload = collectFlightEditPayload();
    const previewContext = buildFlightPopupContext(previewPayload, meta);
    if (dateLabel) {
        dateLabel.textContent = targetStatus === 'started'
            ? UI.modalStartDate
            : '\u0414\u0430\u0442\u0430 \u0437\u0430\u0432\u0435\u0440\u0448\u0435\u043d\u0438\u044f \u043f\u0435\u0440\u0435\u0432\u043e\u0437\u043a\u0438';
    }
    if (titleEl) {
        titleEl.textContent = targetStatus === 'started'
            ? UI.modalStartTitle
            : '\u041f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0432 \u00ab\u0413\u0440\u0443\u0437 \u0441\u0434\u0430\u043d\u00bb';
    }
    if (confirmBtn) {
        confirmBtn.textContent = targetStatus === 'started' ? UI.modalToStarted : UI.labelToCompleted;
    }
    if (preview) {
        const zayavkiRaw = previewPayload.zayavki_ids || meta.zayavki_ids || '';
        const ids = String(zayavkiRaw).split(',').map(v => v.trim()).filter(Boolean);
        const totalKg = Number(meta.total_kg || 0);
        preview.innerHTML = `
            <div><strong>\u0420\u0435\u0439\u0441 #${routeId}</strong></div>
            <div>\u0421\u0442\u0430\u0442\u0443\u0441: ${escapeHtml(statusNames[String(meta.status || '')] || String(meta.status || TXT.notSpecified))} \u2192 ${escapeHtml(statusNames[targetStatus] || targetStatus)}</div>
            <div>${TXT.requestsCount}: ${ids.length}</div>
            <div>${TXT.totalWeight}: ${Math.round(totalKg).toLocaleString('ru-RU')} ${UI.kg}</div>
            <div>${UI.labelDriver}: ${escapeHtml(previewContext.driver_compact)}</div>
            <div>\u041f\u0435\u0440\u0438\u043e\u0434: ${escapeHtml(previewContext.period)}</div>
        `;
    }
    modal.style.display = 'flex';
}

async function postRouteAction(action, payload) {
    const response = await fetch('map_files/save_planned_route.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...(payload || {}), action })
    });
    const text = await response.text();
    if (!response.ok) {
        console.error('save_planned_route HTTP error', { status: response.status, url: 'map_files/save_planned_route.php', body: text.slice(0, 2000) });
        throw new Error('HTTP ' + response.status + ': ' + text.slice(0, 200));
    }
    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        console.error('save_planned_route non-JSON', { status: response.status, body: text.slice(0, 2000) });
        throw new Error('Сервер вернул не JSON. Подробности в консоли.');
    }
    return data;
}

/**
 * Build a structured context object for UI popup templates.
 * Priority: current DOM form > payload > meta (fallback).
 *
 * @param {Object|null} payload - From collectFlightEditPayload() or null
 * @param {Object|null} meta   - From getRouteMetaById() or currentEditingMeta (fallback only)
 * @returns {Object} context with route_id, driver_compact, period, cost, zayavki_count, etc.
 */
function buildFlightPopupContext(payload, meta) {
    payload = payload || {};
    meta = meta || {};
    const routeId = payload.id || meta.id || 0;
    const driverId = Number(payload.driver_id || meta.driver_id || 0);
    const driverSelect = document.getElementById('edit_driver_id');
    let driverCompact = '';
    if (driverId > 0) {
        if (driverSelect && driverSelect.selectedOptions && driverSelect.selectedOptions[0]) {
            const rawText = driverSelect.selectedOptions[0].textContent || '';
            driverCompact = formatDriverCompactLabel(rawText);
        }
    }
    if (!driverCompact) {
        driverCompact = formatDriverCompactLabel(meta.driver_label || '');
    }
    if (!driverCompact) {
        driverCompact = UI.driverMissing;
    }

    const fromVal = payload.planned_start_date_from || document.getElementById('edit_planned_start_date_from')?.value || meta.planned_start_date_from || '';
    const toVal = payload.planned_start_date_to || document.getElementById('edit_planned_start_date_to')?.value || meta.planned_start_date_to || '';
    const period = (fromVal || toVal) ? (fromVal || TXT.notSpecified) + ' ' + UI.emDash + ' ' + (toVal || TXT.notSpecified) : TXT.notSpecified;

    const costRaw = payload.cost || document.getElementById('edit_cost')?.value || meta.cost || '';
    const cost = costRaw !== '' ? formatRouteCost(costRaw) + ' \u20BD' : TXT.notSpecified;

    const zayavkiRaw = payload.zayavki_ids || document.getElementById('edit_zayavki_ids')?.value || meta.zayavki_ids || '';
    const zayavkiIds = String(zayavkiRaw).split(',').map(v => v.trim()).filter(v => /^\d+$/.test(v));
    const zayavkiCount = zayavkiIds.length;

    const actualStart = payload.actual_start_date || document.getElementById('edit_actual_start_date')?.value || meta.actual_start_date || '';
    const actualEnd = payload.actual_end_date || document.getElementById('edit_actual_end_date')?.value || meta.actual_end_date || '';

    const routeTypeVal = payload.route_type || document.getElementById('edit_route_type')?.value || meta.route_type || '';
    const routeTypeLabel = getRouteTypeLabel(routeTypeVal, meta.unload_type || 'OO');

    const statusVal = document.getElementById('edit_current_status')?.value || meta.status || '';

    return {
        route_id: String(routeId),
        title: resolveRouteTitle(meta) || '',
        driver_compact: driverCompact,
        driver_full: driverSelect?.selectedOptions?.[0]?.textContent || '',
        date_from: fromVal,
        date_to: toVal,
        period: period,
        cost: cost,
        zayavki_ids: zayavkiIds.join(','),
        zayavki_count: zayavkiCount,
        route_type_label: routeTypeLabel,
        status_label: statusNames[statusVal] || statusVal || TXT.notSpecified,
        actual_start_date: actualStart,
        actual_end_date: actualEnd,
        source_warehouse_id: payload.source_warehouse_id || meta.source_warehouse_id || '',
        destination_warehouse_id: payload.destination_warehouse_id || meta.destination_warehouse_id || '',
    };
}

/**
 * Render a UI popup template by key with context substitution.
 * Falls back to traditional text if template is missing or disabled.
 *
 * @param {string} popupKey    - Template key (e.g., 'route_planned_to_found_confirm')
 * @param {Object} context     - Key-value pairs for placeholder substitution
 * @param {string} fallbackText - Fallback text if template unavailable
 * @returns {string} Rendered text
 */
function renderUiPopupTemplate(popupKey, context, fallbackText) {
    var tpl = (window.MAP_BOOTSTRAP && window.MAP_BOOTSTRAP.uiPopupTemplates)
        ? window.MAP_BOOTSTRAP.uiPopupTemplates[popupKey]
        : null;
    if (!tpl || !tpl.template_text) {
        return fallbackText;
    }
    var rendered = tpl.template_text.replace(/\{(\w+)\}/g, function(match, key) {
        return (context && context.hasOwnProperty(key) && context[key] !== null && context[key] !== undefined)
            ? String(context[key])
            : (TXT.notSpecified || '\u043d\u0435 \u0443\u043a\u0430\u0437\u0430\u043d\u043e');
    });
    // Prepend title if present and not empty
    if (tpl.title && tpl.title.trim() !== '') {
        // Replace placeholders in title too
        var titleRendered = tpl.title.replace(/\{(\w+)\}/g, function(match, key) {
            return (context && context.hasOwnProperty(key) && context[key] !== null && context[key] !== undefined)
                ? String(context[key])
                : (TXT.notSpecified || '');
        });
        rendered = titleRendered + '\n\n' + rendered;
    }
    return rendered;
}

function collectFlightEditPayload() {
    const idInput = document.getElementById('edit_flight_id');
    const driverInput = document.getElementById('edit_driver_id');
    const fromInput = document.getElementById('edit_planned_start_date_from');
    const toInput = document.getElementById('edit_planned_start_date_to');
    const costInput = document.getElementById('edit_cost');
    const commentInput = document.getElementById('edit_comment');
    const zayavkiInput = document.getElementById('edit_zayavki_ids');
    const actualFromInput = document.getElementById('edit_actual_start_date');
    const actualToInput = document.getElementById('edit_actual_end_date');
    const routeTypeInput = document.getElementById('edit_route_type');
    const sourceWarehouseInput = document.getElementById('edit_source_warehouse_id');
    const destinationWarehouseInput = document.getElementById('edit_destination_warehouse_id');
    const legalEntityInput = document.querySelector('input[name="edit_legal_entity_code"]:checked');
    const flightId = Number(idInput ? idInput.value : 0);
    const routeType = normalizeRouteType(routeTypeInput ? routeTypeInput.value : (currentEditingMeta?.route_type || ''), currentEditingMeta?.unload_type || 'OO');
    return {
        id: flightId,
        driver_id: Number(driverInput ? driverInput.value : 0),
        planned_start_date_from: fromInput ? fromInput.value : '',
        planned_start_date_to: toInput ? toInput.value : '',
        actual_start_date: actualFromInput ? actualFromInput.value : '',
        actual_end_date: actualToInput ? actualToInput.value : '',
        cost: costInput ? costInput.value : '',
        comment: commentInput ? commentInput.value : '',
        zayavki_ids: zayavkiInput ? zayavkiInput.value : '',
        unload_type: resolveUnloadTypeByRouteType(routeType),
        route_type: routeType,
        legal_entity_code: legalEntityInput ? legalEntityInput.value : '',
        source_warehouse_id: sourceWarehouseInput ? sourceWarehouseInput.value : '',
        destination_warehouse_id: destinationWarehouseInput ? destinationWarehouseInput.value : ''
    };
}

async function saveFlightEdit(options = {}) {
    const idInput = document.getElementById('edit_flight_id');
    const driverInput = document.getElementById('edit_driver_id');
    const zayavkiInput = document.getElementById('edit_zayavki_ids');
    const statusInput = document.getElementById('edit_current_status');

    const flightId = Number(idInput ? idInput.value : 0);
    if (!flightId) {
        alert(UI.msgFlightDataNotFound);
        return false;
    }
    const commentVal = String(document.getElementById('edit_comment')?.value || '').trim();
    const idsForSave = String(zayavkiInput ? zayavkiInput.value : '')
        .split(',')
        .map(v => v.trim())
        .filter(v => /^\d+$/.test(v));
    const currentStatus = String(statusInput ? statusInput.value : '');
    const isStrictEdit = currentStatus === 'found' || currentStatus === 'started';
    const comboValid = validateDriverComboboxSelection();
    if (isStrictEdit && !comboValid) {
        showFlightValidationErrors({ driver_id: true }, UI.msgSaveValidationTitle);
        return false;
    }
    if (!isStrictEdit && !comboValid) {
        const driverInputText = document.getElementById('edit_driver_input');
        if (driverInputText) driverInputText.value = '';
        if (driverInput) driverInput.value = '';
        const driverError = document.getElementById('edit_driver_error');
        if (driverError) {
            driverError.style.display = 'none';
            driverError.textContent = '';
        }
    }
    const saveErrors = isStrictEdit ? validateRequiredForStrictTransition() : {};
    if (!document.querySelector('input[name="edit_legal_entity_code"]:checked')) {
        saveErrors.legal_entity_code = true;
    }
    if (Object.keys(saveErrors).length > 0) {
        showFlightValidationErrors(saveErrors, UI.msgSaveValidationTitle);
        return false;
    }

    const payload = collectFlightEditPayload();

    const status = String(statusInput ? statusInput.value : '');
    const previewEl = document.getElementById('flightChangePreview');
    if ((status === 'found' || status === 'started') && currentEditingMeta) {
        const previewHtml = buildFoundChangePreview(currentEditingMeta);
        if (previewEl) {
            if (previewHtml) {
                previewEl.style.display = 'block';
                previewEl.innerHTML = previewHtml;
            } else {
                previewEl.style.display = 'none';
                previewEl.innerHTML = '';
            }
        }
        const warnText = status === 'started'
            ? '\u0420\u0435\u0439\u0441 \u0443\u0436\u0435 \u043d\u0430\u0445\u043e\u0434\u0438\u0442\u0441\u044f \u0432 \u0432\u044b\u043f\u043e\u043b\u043d\u0435\u043d\u0438\u0438. \u041f\u043e\u0441\u043b\u0435 \u0441\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u0438\u044f \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f \u0431\u0443\u0434\u0443\u0442 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u044b \u0432 MAX. \u041f\u0440\u043e\u0434\u043e\u043b\u0436\u0438\u0442\u044c?'
            : '\u041f\u043e\u0441\u043b\u0435 \u0441\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u0438\u044f \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f \u0431\u0443\u0434\u0443\u0442 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u044b \u0432 MAX. \u041f\u0440\u043e\u0434\u043e\u043b\u0436\u0438\u0442\u044c?';
        if (!options.silent && previewHtml && !confirm(warnText)) {
            return false;
        }
    }

    try {
        const result = await postRouteAction('save', payload);
        if (result && result.success) {
            await loadPlannedRoutes();
            if (options.closeModal !== false) {
                closeFlightEditModal();
            }
            if (!options.silent) {
                window.location.reload();
            }
            return true;
        }
        if (result && result.errors && typeof result.errors === 'object') {
            showFlightValidationErrors(result.errors);
        }
        alert((result && result.message) ? result.message : UI.msgSaveFailed);
        return false;
    } catch (e) {
        console.error('saveFlightEdit error:', e);
        alert(UI.msgNetworkUpdateFlight);
        return false;
    }
}

function hasUnsavedFlightEditChanges() {
    if (!currentEditingMeta) return false;
    const isStarted = currentEditingMeta.status === 'started';
    const driverInput = document.getElementById('edit_driver_id');
    if (Number(driverInput?.value || 0) !== Number(currentEditingMeta.driver_id || 0)) return true;
    const costInput = document.getElementById('edit_cost');
    if (String(costInput?.value || '').trim() !== String(currentEditingMeta.cost ?? '')) return true;
    const zayavkiInput = document.getElementById('edit_zayavki_ids');
    if (String(zayavkiInput?.value || '').replace(/\s/g, '') !== String(currentEditingMeta.zayavki_ids || '').replace(/\s/g, '')) return true;
    const commentInput = document.getElementById('edit_comment');
    if ((commentInput?.value || '').trim() !== (currentEditingMeta.comment || '').trim()) return true;
    const routeTypeInput = document.getElementById('edit_route_type');
    const curRt = normalizeRouteType(routeTypeInput?.value || '', currentEditingMeta.unload_type || 'OO');
    const metaRt = normalizeRouteType(currentEditingMeta.route_type || '', currentEditingMeta.unload_type || 'OO');
    if (curRt !== metaRt) return true;
    const legalEntityInput = document.querySelector('input[name="edit_legal_entity_code"]:checked');
    if (String(legalEntityInput?.value || '') !== String(currentEditingMeta.legal_entity_code || '')) return true;
    const srcWh = document.getElementById('edit_source_warehouse_id');
    const dstWh = document.getElementById('edit_destination_warehouse_id');
    if (Number(srcWh?.value || 0) !== Number(currentEditingMeta.source_warehouse_id || 0)) return true;
    if (Number(dstWh?.value || 0) !== Number(currentEditingMeta.destination_warehouse_id || 0)) return true;
    const dateFrom = document.getElementById(isStarted ? 'edit_actual_start_date' : 'edit_planned_start_date_from');
    const dateTo = document.getElementById(isStarted ? 'edit_actual_end_date' : 'edit_planned_start_date_to');
    const curFrom = (dateFrom?.value || '').trim();
    const curTo = (dateTo?.value || '').trim();
    const metaFrom = isStarted ? String(currentEditingMeta.actual_start_date || '') : String(currentEditingMeta.planned_start_date_from || '');
    const metaTo = isStarted ? String(currentEditingMeta.actual_end_date || '') : String(currentEditingMeta.planned_start_date_to || '');
    if (curFrom !== metaFrom.trim() || curTo !== metaTo.trim()) return true;
    return false;
}

async function saveBeforeStatusTransition(transitionFn, routeId) {
    if (hasUnsavedFlightEditChanges()) {
        const confirmFallback = '\u0412 \u0440\u0435\u0439\u0441\u0435 \u0435\u0441\u0442\u044c \u043d\u0435\u0441\u043e\u0445\u0440\u0430\u043d\u0451\u043d\u043d\u044b\u0435 \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f.\n\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f \u0438 \u0432\u044b\u043f\u043e\u043b\u043d\u0438\u0442\u044c \u043f\u0435\u0440\u0435\u0445\u043e\u0434 \u0441\u0442\u0430\u0442\u0443\u0441\u0430?';
        const ok = confirm(renderUiPopupTemplate('route_unsaved_before_transition_confirm', {}, confirmFallback));
        if (!ok) return false;
        const saved = await saveFlightEdit({ closeModal: false, silent: true });
        if (!saved) {
            const failFallback = '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f \u0440\u0435\u0439\u0441\u0430. \u041f\u0435\u0440\u0435\u0445\u043e\u0434 \u0441\u0442\u0430\u0442\u0443\u0441\u0430 \u043e\u0442\u043c\u0435\u043d\u0451\u043d.';
            alert(renderUiPopupTemplate('route_transition_failed', {}, failFallback));
            return false;
        }
    }
    await transitionFn(routeId);
    return true;
}

async function transferPlannedToFound(routeId) {
    if (!validateDriverComboboxSelection()) {
        return;
    }
    const preErrors = validateRequiredForStrictTransition();
    if (Object.keys(preErrors).length > 0) {
        showFlightValidationErrors(preErrors);
        return;
    }

    const payload = collectFlightEditPayload();
    const meta = getRouteMetaById(routeId, 'planned') || getRouteMetaById(routeId, 'found') || {};
    const context = buildFlightPopupContext(payload, meta);

    const fallbackText = [
        `\u0421\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441 \u2116${routeId}?`,
        '',
        '\u0411\u0443\u0434\u0443\u0442 \u0437\u0430\u0444\u0438\u043a\u0441\u0438\u0440\u043e\u0432\u0430\u043d\u044b \u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044c, \u0434\u0430\u0442\u044b, \u0441\u0442\u043e\u0438\u043c\u043e\u0441\u0442\u044c \u0438 \u0437\u0430\u044f\u0432\u043a\u0438.',
        '\u0412 MAX \u0431\u0443\u0434\u0435\u0442 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u043e \u0443\u0432\u0435\u0434\u043e\u043c\u043b\u0435\u043d\u0438\u0435.',
        '',
        `${TXT.requestsCount}: ${context.zayavki_count}`,
        `${UI.labelDriver}: ${context.driver_compact}`
    ].join('\n');

    const text = renderUiPopupTemplate('route_planned_to_found_confirm', context, fallbackText);
    if (!confirm(text)) return;

    try {
        const result = await postRouteAction('transition', {
            id: Number(routeId),
            target_status: 'found',
            zayavki_ids: payload.zayavki_ids || meta.zayavki_ids || '',
            driver_id: Number(payload.driver_id || meta.driver_id || 0),
            assigned_manager_id: meta.assigned_manager_id || '',
            planned_start_date_from: payload.planned_start_date_from || meta.planned_start_date_from || '',
            planned_start_date_to: payload.planned_start_date_to || meta.planned_start_date_to || '',
            cost: payload.cost || meta.cost || '',
            name: payload.comment || resolveRouteTitle(meta) || '',
            unload_type: payload.unload_type || resolveUnloadTypeByRouteType(payload.route_type || meta.route_type || ''),
            route_type: payload.route_type || meta.route_type || '',
            legal_entity_code: payload.legal_entity_code || meta.legal_entity_code || '',
            source_warehouse_id: payload.source_warehouse_id || meta.source_warehouse_id || '',
            destination_warehouse_id: payload.destination_warehouse_id || meta.destination_warehouse_id || ''
        });
        if (result && result.success) {
            window.location.reload();
            return;
        }
        if (result && result.errors && typeof result.errors === 'object') {
            showFlightValidationErrors(result.errors);
        }
        alert((result && result.message) ? result.message : UI.msgTransferFailed);
    } catch (e) {
        console.error('transferPlannedToFound error:', e);
        alert(UI.msgNetworkTransfer);
    }
}

async function confirmTransferToStarted() {
    const idInput = document.getElementById('start_flight_id');
    const targetInput = document.getElementById('start_target_status');
    const dateInput = document.getElementById('start_actual_start_date');
    const flightId = Number(idInput ? idInput.value : 0);
    const targetStatus = String(targetInput ? targetInput.value : 'started').trim() || 'started';
    if (!flightId) {
        alert(UI.msgInvalidFlightId);
        return;
    }
    if (targetStatus === 'started' && !String(dateInput ? dateInput.value : '').trim()) {
        alert('\u0423\u043a\u0430\u0436\u0438\u0442\u0435 \u0434\u0430\u0442\u0443 \u043d\u0430\u0447\u0430\u043b\u0430 \u0432\u044b\u0432\u043e\u0437\u0430.');
        if (dateInput) {
            dateInput.style.borderColor = '#e74c3c';
            dateInput.focus();
        }
        return;
    }
    if (targetStatus === 'completed' && !String(dateInput ? dateInput.value : '').trim()) {
        alert(UI.msgNeedEndDate);
        return;
    }

    try {
        const result = await postRouteAction('transition', {
            id: flightId,
            target_status: targetStatus,
            actual_start_date: targetStatus === 'started' ? (dateInput ? dateInput.value : '') : '',
            actual_end_date: targetStatus === 'completed' ? (dateInput ? dateInput.value : '') : ''
        });
        if (result && result.success) {
            window.location.reload();
            return;
        }
        if (result && result.errors && typeof result.errors === 'object') {
            showFlightValidationErrors(result.errors);
        }
        alert((result && result.message) ? result.message : UI.msgTransferFailed);
    } catch (e) {
        console.error('confirmTransferToStarted error:', e);
        alert(UI.msgNetworkTransfer);
    }
}

async function transferToPlanned(routeId) {
    const ctx = { route_id: String(routeId) };
    if (!confirm(renderUiPopupTemplate('route_rollback_to_planned_confirm', ctx, UI.msgBackToPlanned.replace('{id}', String(routeId))))) return;
    try {
        const result = await postRouteAction('transition', { id: Number(routeId), target_status: 'planned_route' });
        if (result && result.success) {
            window.location.reload();
            return;
        }
        alert((result && result.message) ? result.message : UI.msgTransferFailed);
    } catch (e) {
        console.error('transferToPlanned error:', e);
        alert(UI.msgNetworkTransfer);
    }
}

async function transferToFound(routeId) {
    const ctx = { route_id: String(routeId) };
    if (!confirm(renderUiPopupTemplate('route_rollback_to_found_confirm', ctx, UI.msgBackToFound.replace('{id}', String(routeId))))) return;
    try {
        const result = await postRouteAction('transition', { id: Number(routeId), target_status: 'found' });
        if (result && result.success) {
            window.location.reload();
            return;
        }
        alert((result && result.message) ? result.message : UI.msgTransferFailed);
    } catch (e) {
        console.error('transferToFound error:', e);
        alert(UI.msgNetworkTransfer);
    }
}

function transferToCompleted(routeId) {
    const ctx = { route_id: String(routeId) };
    if (!confirm(renderUiPopupTemplate('route_complete_confirm', ctx, UI.msgToCompleted.replace('{id}', String(routeId))))) return;
    openStartConfirmModal(routeId, 'completed');
}

function buildRouteManageMenu(routeId, source) {
    return '';
}

function getWarehouseStockById(warehouseId) {
    var key = String(warehouseId || '').trim();
    if (!key || !warehouseStockData) return null;
    return warehouseStockData[key] || warehouseStockData[Number(key)] || null;
}

function createWarehouseMarkerSvgDataUri(label, bgColor, borderColor) {
    const safeLabel = String(label || 'СКЛАД')
        .replace(/&/g, '&')
        .replace(/</g, '<')
        .replace(/>/g, '>')
        .replace(/"/g, '"');
    const svg =
        '<svg xmlns="http://www.w3.org/2000/svg" width="58" height="32" viewBox="0 0 58 32">' +
        '<path d="M4 1 H54 Q57 1 57 4 V22 Q57 25 54 25 H36 L29 31 L22 25 H4 Q1 25 1 22 V4 Q1 1 4 1 Z" fill="' + bgColor + '" stroke="' + borderColor + '" stroke-width="2"/>' +
        '<text x="29" y="18" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" font-weight="700" fill="#ffffff">' + safeLabel + '</text>' +
        '</svg>';
    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

function buildWarehouseBalloon(warehouse) {
    const id = warehouse.id || 0;
    const name = String(warehouse.name || ('Склад #' + id)).trim();
    const address = String(warehouse.address || '').trim();
    const stock = getWarehouseStockById(id);
    const nettoSum = stock ? Number(stock.mass_netto_sum || 0) : 0;
    const bruttoSum = stock ? Number(stock.mass_brutto_sum || 0) : 0;
    const volSum = stock ? Number(stock.volume_sum || 0) : 0;
    const reqCount = stock ? (stock.request_count || 0) : 0;

    let html = '<div style="padding:10px;min-width:360px;max-width:600px;font-size:13px;line-height:1.5;">';
    html += `<div style="font-size:18px;font-weight:700;margin-bottom:4px;">${escapeHtml(name)}</div>`;
    html += `<div style="color:#666;margin-bottom:10px;">${escapeHtml(address || TXT.notSpecified)}</div>`;

    if (stock) {
        html += '<div style="background:#f0f7ff;border-radius:6px;padding:8px;margin-bottom:10px;">';
        html += `<div><strong>Заявок на складе:</strong> ${reqCount}</div>`;
        html += `<div><strong>Вес нетто:</strong> ${Number(nettoSum).toFixed(3)} т</div>`;
        html += `<div><strong>Вес брутто:</strong> ${Number(bruttoSum).toFixed(3)} т</div>`;
        html += `<div><strong>Объём:</strong> ${Number(volSum).toFixed(3)} м³</div>`;
        html += '</div>';

        if (Array.isArray(stock.inbound_routes) && stock.inbound_routes.length > 0) {
            html += '<div style="margin-bottom:8px;"><strong style="color:#27ae60;">Поступления на склад:</strong>';
            stock.inbound_routes.forEach(function(r) {
                html += `<div style="font-size:12px;margin:2px 0;">• Рейс #${r.flight_id} — ${escapeHtml(r.movement_label || r.movement_type)} — ${r.request_count} заяв. — ${Number(r.mass_netto_sum).toFixed(3)} т</div>`;
            });
            html += '</div>';
        }

        if (Array.isArray(stock.outbound_routes) && stock.outbound_routes.length > 0) {
            html += '<div style="margin-bottom:8px;"><strong style="color:#e74c3c;">Списания со склада:</strong>';
            stock.outbound_routes.forEach(function(r) {
                html += `<div style="font-size:12px;margin:2px 0;">• Рейс #${r.flight_id} — ${escapeHtml(r.movement_label || r.movement_type)} — ${r.request_count} заяв. — ${Number(r.mass_netto_sum).toFixed(3)} т</div>`;
            });
            html += '</div>';
        }
    } else {
        html += '<div style="color:#888;">Нет данных о складских остатках</div>';
    }
    html += '</div>';
    return html;
}

let selectedWarehouseId = null;

function toggleWarehouseSelection(warehouseId) {
    var stock = getWarehouseStockById(warehouseId);
    if (!stock || !Array.isArray(stock.requests) || stock.requests.length === 0) {
        return false;
    }
    var stockIds = stock.requests.map(function(r) { return String(r.zayavka_id); });
    var allSelected = stockIds.every(function(id) { return selectedOrder.indexOf(id) !== -1; });

    if (allSelected) {
        stockIds.forEach(function(id) {
            var idx = selectedOrder.indexOf(id);
            if (idx !== -1) selectedOrder.splice(idx, 1);
        });
        selectedWarehouseId = null;
    } else {
        var addedCount = 0;
        stock.requests.forEach(function(req) {
            var zid = String(req.zayavka_id || '').trim();
            if (!zid) return;
            if (selectedOrder.indexOf(zid) === -1) {
                selectedOrder.push(zid);
                addedCount++;
            }
            var kg = Math.round(Number(req.mass_netto || 0) * 1000);
            if (kg > 0) {
                weightById[zid] = kg;
            }
        });
        selectedWarehouseId = String(warehouseId || '').trim();
    }
    renderWarehousesLayer();
    refreshMarkerStyles();
    updateSelectionUI();
    return true;
}

function renderWarehousesLayer() {
    if (!warehousesCollection || !map) return;
    warehousesCollection.removeAll();
    if (!warehousesVisible) return;
    (Array.isArray(warehousesData) ? warehousesData : []).forEach((warehouse) => {
        if (!warehouse) return;
        const lat = Number(warehouse.latitude);
        const lon = Number(warehouse.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
        const id = String(warehouse.id || '').trim();
        if (!id) return;
        const stock = getWarehouseStockById(id);
        const hasStock = stock
            && Number(stock.request_count || 0) > 0
            && Number(stock.mass_netto_sum || 0) > 0
            && Array.isArray(stock.requests)
            && stock.requests.length > 0;
        if (!hasStock) return;
        const name = String(warehouse.name || ('Склад #' + id)).trim();
        const isSelectedWH = String(selectedWarehouseId || '') === id;
        const bgColor = isSelectedWH ? '#27ae60' : '#6c757d';
        const borderColor = isSelectedWH ? '#1f8f4d' : '#4f565c';
        const iconHref = createWarehouseMarkerSvgDataUri(UI.labelWarehouseMarker || 'СКЛАД', bgColor, borderColor);

        const placemark = new ymaps.Placemark([lat, lon], {
            hintContent: name + ' — ' + stock.request_count + ' заяв.',
            balloonContent: buildWarehouseBalloon(warehouse)
        }, {
            iconLayout: 'default#image',
            iconImageHref: iconHref,
            iconImageSize: [58, 32],
            iconImageOffset: [-29, -32],
            openBalloonOnClick: false,
            openHintOnHover: true,
            zIndex: 480
        });

        placemark.events.add('click', function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }
            toggleWarehouseSelection(id);
        });

        placemark.events.add('dblclick', function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }
            placemark.balloon.open();
        });

        warehousesCollection.add(placemark);
    });
}

function toggleWarehouseLayer(visible) {
    warehousesVisible = !!visible;
    renderWarehousesLayer();
}

async function loadWarehouses(keepExistingOnError = true) {
    try {
        const response = await fetch('map_files/get_warehouses.php');
        const data = await response.json();
        if (!response.ok || !data || !data.success || !Array.isArray(data.warehouses)) {
            if (!keepExistingOnError) {
                warehousesData = [];
            }
            await loadWarehouseStock();
            renderWarehousesLayer();
            return false;
        }
        warehousesData = data.warehouses;
        await loadWarehouseStock();
        renderWarehousesLayer();
        return true;
    } catch (error) {
        if (!keepExistingOnError) {
            warehousesData = [];
        }
        await loadWarehouseStock();
        renderWarehousesLayer();
        return false;
    }
}

async function loadWarehouseStock() {
    try {
        const response = await fetch('map_files/get_warehouse_stock_map.php');
        const data = await response.json();
        if (data && data.success && data.warehouses) {
            warehouseStockData = data.warehouses;
            Object.keys(warehouseStockData).forEach(function(whKey) {
                var wh = warehouseStockData[whKey];
                if (wh && Array.isArray(wh.requests)) {
                    wh.requests.forEach(function(req) {
                        var zid = String(req.zayavka_id || '').trim();
                        if (zid && !(zid in weightById)) {
                            weightById[zid] = Math.round((req.mass_netto || 0) * 1000);
                        }
                    });
                }
            });
        }
    } catch (e) {
        console.error('loadWarehouseStock failed:', e);
    }
}

async function loadManagers() {
    const select = document.getElementById('managerScopeSelect');
    const errorBox = document.getElementById('managerScopeError');
    if (!select) return;

    select.disabled = true;
    if (errorBox) {
        errorBox.style.display = 'none';
        errorBox.textContent = '';
    }

    try {
        const response = await fetch('map_files/get_managers.php');
        const data = await response.json();
        if (!response.ok || !data || !data.success || !Array.isArray(data.managers)) {
            throw new Error((data && data.message) ? data.message : '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u043e\u0432');
        }

        currentManagers = Array.isArray(data.managers) ? data.managers : [];
        const savedManagerId = String(localStorage.getItem(MANAGER_STORAGE_KEY) || '').trim();
        select.innerHTML = '<option value="">\u041f\u043e\u043a\u0430\u0437\u0430\u0442\u044c \u0432\u0441\u0435</option>';

        let hasSaved = false;
        currentManagers.forEach(manager => {
            if (!manager || !manager.id) return;
            const value = String(manager.id);
            const option = document.createElement('option');
            option.value = value;
            option.textContent = String(manager.name || (`${UI.driverPrefix}${value}`));
            select.appendChild(option);
            if (savedManagerId === value) {
                hasSaved = true;
            }
        });

        if (savedManagerId && hasSaved) {
            select.value = savedManagerId;
            managerScopeId = savedManagerId;
        } else {
            select.value = '';
            managerScopeId = '';
            if (savedManagerId && !hasSaved) {
                localStorage.removeItem(MANAGER_STORAGE_KEY);
            }
        }

        managerScopeInitialized = true;
        select.disabled = false;
        syncCreateRouteManagerSelect();
        await loadPlannedRoutes();
    } catch (error) {
        managerScopeInitialized = true;
        currentManagers = [];
        managerScopeId = '';
        select.value = '';
        select.disabled = true;
        if (errorBox) {
            errorBox.style.display = 'block';
            errorBox.textContent = '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u043e\u0432';
        }
        syncCreateRouteManagerSelect();
        await loadPlannedRoutes();
    }
}

function syncCreateRouteManagerSelect() {
    ensureCreateRouteModal();
    const select = document.getElementById('create_route_manager_id');
    if (!select) return;

    const preferred = String(managerScopeId || localStorage.getItem(MANAGER_STORAGE_KEY) || '').trim();
    select.innerHTML = `<option value="">${UI.msgChooseManagerOption}</option>`;

    let hasPreferred = false;
    (Array.isArray(currentManagers) ? currentManagers : []).forEach(manager => {
        if (!manager || !manager.id) return;
        const value = String(manager.id);
        const option = document.createElement('option');
        option.value = value;
        option.textContent = String(manager.name || (`${UI.driverPrefix}${value}`));
        select.appendChild(option);
        if (preferred && preferred === value) {
            hasPreferred = true;
        }
    });

    select.value = hasPreferred ? preferred : '';
}

function ensureCreateRouteModal() {
    if (document.getElementById('createRouteModal')) return;
    const container = document.createElement('div');
    container.innerHTML = `
<div class="flight-modal-backdrop" id="createRouteModal" style="display:none;">
    <div class="flight-modal flight-modal-confirm">
        <div class="flight-modal-title">\u0421\u043e\u0437\u0434\u0430\u043d\u0438\u0435 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430</div>
        <label class="flight-modal-label" for="create_route_name">\u041d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430</label>
        <input class="flight-modal-input" type="text" id="create_route_name" maxlength="255">
        <label class="flight-modal-label" for="create_route_manager_id">\u041c\u0435\u043d\u0435\u0434\u0436\u0435\u0440</label>
        <select class="flight-modal-input" id="create_route_manager_id">
            <option value="">\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u0430</option>
        </select>
        <fieldset class="legal-entity-field" id="create_legal_entity">
            <legend class="flight-modal-label">\u042e\u0440\u0438\u0434\u0438\u0447\u0435\u0441\u043a\u043e\u0435 \u043b\u0438\u0446\u043e *</legend>
            <div class="legal-entity-options">
                <label class="legal-entity-option">
                    <input type="radio" name="create_legal_entity_code" value="planex">
                    <span>\u041e\u041e\u041e \u00ab\u041f\u043b\u0430\u043d\u044d\u043a\u0441\u00bb</span>
                </label>
                <label class="legal-entity-option">
                    <input type="radio" name="create_legal_entity_code" value="mg_logistic">
                    <span>\u041e\u041e\u041e \u00ab\u041c\u0413 \u041b\u043e\u0433\u0438\u0441\u0442\u0438\u043a\u00bb</span>
                </label>
            </div>
        </fieldset>
        <label class="flight-modal-label" for="create_route_type">\u0422\u0438\u043f \u0440\u0435\u0439\u0441\u0430</label>
        <select class="flight-modal-input" id="create_route_type">
            <option value="generator_to_utilizer">\u041e\u0431\u044b\u0447\u043d\u0430\u044f \u0432\u044b\u0433\u0440\u0443\u0437\u043a\u0430 / \u0423\u0442\u0438\u043b\u0438\u0437\u0430\u0442\u043e\u0440</option>
            <option value="generator_to_warehouse">\u0412\u044b\u0433\u0440\u0443\u0437\u043a\u0430 \u043d\u0430 \u0441\u043a\u043b\u0430\u0434</option>
            <option value="warehouse_to_warehouse">\u041f\u0435\u0440\u0435\u043c\u0435\u0449\u0435\u043d\u0438\u0435 \u0441\u043a\u043b\u0430\u0434 \u2192 \u0441\u043a\u043b\u0430\u0434</option>
            <option value="warehouse_to_utilizer">\u0412\u044b\u0432\u043e\u0437 \u0441\u043e \u0441\u043a\u043b\u0430\u0434\u0430 \u043d\u0430 \u0443\u0442\u0438\u043b\u0438\u0437\u0430\u0442\u043e\u0440</option>
        </select>
        <div class="flight-warehouse-group" id="create_warehouse_group" style="display:none;">
            <div class="flight-warehouse-wrap" id="create_source_warehouse_wrap" style="display:none;">
                <label class="flight-modal-label" for="create_source_warehouse_id">\u0421\u043a\u043b\u0430\u0434 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u044f</label>
                <select class="flight-modal-input" id="create_source_warehouse_id"></select>
            </div>
            <div class="flight-warehouse-wrap" id="create_destination_warehouse_wrap" style="display:none;">
                <label class="flight-modal-label" for="create_destination_warehouse_id">\u0421\u043a\u043b\u0430\u0434 \u043d\u0430\u0437\u043d\u0430\u0447\u0435\u043d\u0438\u044f</label>
                <select class="flight-modal-input" id="create_destination_warehouse_id"></select>
            </div>
        </div>
        <div class="flight-validation-errors" id="createRouteErrors" style="display:none;"></div>
        <div class="flight-modal-actions">
            <button class="route-action-btn route-action-main route-edit-btn" id="createRouteConfirmBtn">\u0421\u043e\u0437\u0434\u0430\u0442\u044c</button>
            <button class="route-action-btn route-action-main" id="createRouteCancelBtn">\u041e\u0442\u043c\u0435\u043d\u0430</button>
        </div>
    </div>
</div>`;
    document.body.appendChild(container.firstElementChild);
}
function loadPlannedRoutes() {
    const container = document.getElementById('plannedRoutesList');
    const foundContainer = document.getElementById('foundRoutesList');
    const startedContainer = document.getElementById('startedRoutesList');
    container.innerHTML = `<div class="route-list-empty">${TXT.loading}</div>`;
    if (foundContainer) {
        foundContainer.innerHTML = `<div class="route-list-empty">${TXT.loading}</div>`;
    }
    if (startedContainer) {
        startedContainer.innerHTML = `<div class="route-list-empty">${TXT.loading}</div>`;
    }
    const managerParam = managerScopeId ? `?manager_id=${encodeURIComponent(managerScopeId)}` : '';
    return fetch(`map_files/get_planned_routes.php${managerParam}`).then(r => r.json()).then(data => {
        if (data && Array.isArray(data.routes)) {
            data.routes.forEach((route) => {
                if (!route || route.id === undefined || route.id === null) return;
                const key = String(route.id);
                const prev = (routeCardsMeta && routeCardsMeta[key]) ? routeCardsMeta[key] : {};
                routeCardsMeta[key] = { ...prev, ...route };
            });
        }
        if(!data.success || !data.routes || !data.routes.length) {
            container.innerHTML = managerScopeId
                ? `<div class="route-list-empty">\u041d\u0435\u0442 \u0440\u0435\u0439\u0441\u043e\u0432 \u0434\u043b\u044f \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u043e\u0433\u043e \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u0430</div>`
                : `<div class="route-list-empty">${TXT.noSavedRoutes}</div>`;
        } else {
            container.innerHTML = data.routes.map(r => {
                const routeMeta = (routeCardsMeta && routeCardsMeta[String(r.id)]) ? routeCardsMeta[String(r.id)] : {};
                const zayCount = Number(r.zayavki_count || routeMeta.zayavki_count || 0);
                const totalKg = Number(r.total_kg || routeMeta.total_kg || 0);
                const driverLabel = formatDriverCompactLabel(routeMeta.driver_label || UI.driverMissing);
                const routeForDate = { ...routeMeta, ...r, status: 'planned_route' };
                const driverLine = buildRouteCardDriverLine(routeForDate, driverLabel);
                const routeCost = (r.cost !== null && r.cost !== undefined && r.cost !== '') ? r.cost : (routeMeta.cost ?? null);
                const costPart = routeCost ? (UI.bullet + parseFloat(routeCost).toLocaleString('ru-RU') + ' \u20BD') : '';
                const unloadPart = getRouteTypeCompactSuffix(r.route_type || routeMeta.route_type, r.unload_type || routeMeta.unload_type);
                return `
                    <div class="route-item route-item-planned" onclick="selectRoute('${r.zayavki_ids}', '${routeCost || ''}', this)" ondblclick="openFlightEditModal(${r.id}, 'planned')" data-route-id="${r.id}" data-route-source="planned" data-route-editable="1">
                        <div class="route-head"><div class="route-name">#${r.id} ${resolveRouteTitle(r) || (UI.routePrefix + r.id)}</div>${buildRouteManageMenu(r.id, 'planned')}</div>
                        <div class="route-meta route-timeline">${driverLine}</div>
                        <div class="route-meta route-metrics">${zayCount} \u0437\u0430\u044f\u0432.${UI.bullet}${Math.round(totalKg).toLocaleString('ru-RU')} ${UI.kg}${costPart}${unloadPart}</div>
                    </div>
                `;
            }).join('');
        }

        currentFoundRoutes = Array.isArray(data.found_routes) ? data.found_routes : [];
        currentStartedRoutes = Array.isArray(data.started_routes) ? data.started_routes : [];
        rebuildFoundRoutesById();
        rebuildManagerScopeKeys(data.routes, currentFoundRoutes, currentStartedRoutes);
        renderTrackersByMode();

        if (foundContainer) {
            if (!Array.isArray(currentFoundRoutes) || currentFoundRoutes.length === 0) {
                foundContainer.innerHTML = managerScopeId
                ? `<div class="route-list-empty">\u041d\u0435\u0442 \u0440\u0435\u0439\u0441\u043e\u0432 \u0434\u043b\u044f \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u043e\u0433\u043e \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u0430</div>`
                    : `<div class="route-list-empty">${TXT.noSavedRoutes}</div>`;
            } else {
                foundContainer.innerHTML = currentFoundRoutes.map(r => {
                    const zayCount = Number(r.zayavki_count || 0);
                    const totalKg = Number(r.total_kg || 0);
                    const routeCost = (r.cost !== null && r.cost !== undefined && r.cost !== '') ? r.cost : null;
                    const costPart = routeCost ? (UI.bullet + parseFloat(routeCost).toLocaleString('ru-RU') + ' \u20BD') : '';
                    const unloadPart = getRouteTypeCompactSuffix(r.route_type, r.unload_type);
                    const driverLabel = formatDriverCompactLabel(r.driver_label || UI.driverMissing);
                    const routeForDate = { ...r, status: 'found' };
                    const driverLine = buildRouteCardDriverLine(routeForDate, driverLabel);
                    return `
                        <div class="route-item route-item-found" onclick="selectRoute('${r.zayavki_ids}', '${routeCost || ''}', this)" ondblclick="openFlightEditModal(${r.id}, 'found')" data-route-id="${r.id}" data-route-source="found" data-route-editable="1">
                            <div class="route-head"><div class="route-name">#${r.id} ${resolveRouteTitle(r) || (UI.routePrefix + r.id)}</div>${buildRouteManageMenu(r.id, 'found')}</div>
                            <div class="route-meta route-timeline">${driverLine}</div>
                            <div class="route-meta route-metrics">${zayCount} \u0437\u0430\u044f\u0432.${UI.bullet}${Math.round(totalKg).toLocaleString('ru-RU')} ${UI.kg}${costPart}${unloadPart}</div>
                        </div>
                    `;
                }).join('');
            }
        }

        if (startedContainer) {
            if (!Array.isArray(currentStartedRoutes) || currentStartedRoutes.length === 0) {
                startedContainer.innerHTML = managerScopeId
                    ? `<div class="route-list-empty">\u041d\u0435\u0442 \u0440\u0435\u0439\u0441\u043e\u0432 \u0434\u043b\u044f \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u043e\u0433\u043e \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u0430</div>`
                    : `<div class="route-list-empty">${TXT.noSavedRoutes}</div>`;
            } else {
                startedContainer.innerHTML = currentStartedRoutes.map(r => {
                    const zayCount = Number(r.zayavki_count || 0);
                    const totalKg = Number(r.total_kg || 0);
                    const routeCost = (r.cost !== null && r.cost !== undefined && r.cost !== '') ? r.cost : null;
                    const costPart = routeCost ? (UI.bullet + parseFloat(routeCost).toLocaleString('ru-RU') + ' \u20BD') : '';
                    const unloadPart = getRouteTypeCompactSuffix(r.route_type, r.unload_type);
                    const driverLabel = formatDriverCompactLabel(r.driver_label || UI.driverMissing);
                    const routeForDate = { ...r, status: 'started' };
                    const driverLine = buildRouteCardDriverLine(routeForDate, driverLabel);
                    return `
                        <div class="route-item route-item-started" onclick="selectRoute('${r.zayavki_ids}', '${routeCost || ''}', this)" ondblclick="openFlightEditModal(${r.id}, 'started')" data-route-id="${r.id}" data-route-source="started" data-route-editable="1">
                            <div class="route-head"><div class="route-name">#${r.id} ${resolveRouteTitle(r) || (UI.routePrefix + r.id)}</div>${buildRouteManageMenu(r.id, 'started')}</div>
                            <div class="route-meta route-timeline">${driverLine}</div>
                            <div class="route-meta route-metrics">${zayCount} \u0437\u0430\u044f\u0432.${UI.bullet}${Math.round(totalKg).toLocaleString('ru-RU')} ${UI.kg}${costPart}${unloadPart}</div>
                        </div>
                    `;
                }).join('');
            }
        }
    }).catch(() => {
        container.innerHTML = `<div class="route-list-empty">${TXT.loadError}</div>`;
        if (foundContainer) {
            foundContainer.innerHTML = `<div class="route-list-empty">${TXT.loadError}</div>`;
        }
        if (startedContainer) {
            startedContainer.innerHTML = `<div class="route-list-empty">${TXT.loadError}</div>`;
        }
    });
}

function selectRoute(idsStr, costVal, element) {
    selectedOrder = [];
    document.querySelectorAll('.route-item').forEach(el => el.classList.remove('active'));
    if(element) element.classList.add('active');
    const ids = idsStr.split(',').map(s=>s.trim()).filter(s=>s);
    selectedOrder = ids;
    const isEditableRoute = !!(element && element.dataset && element.dataset.routeEditable === '1');
    editingRouteId = isEditableRoute ? (element ? element.dataset.routeId : null) : null;
    document.getElementById('route-cost-input').value = costVal;
    const statusEl = document.getElementById('route-edit-status');
    const saveBtn = document.getElementById('saveRouteBtn');
    const editBtn = document.getElementById('editRouteDataBtn');
    if (editingRouteId) {
        statusEl.innerHTML = `<span style="color:#9c27b0; font-weight:bold;">\u270F\uFE0F ${UI.routeEditTitle} #${editingRouteId}</span>`;
        saveBtn.textContent = UI.routeUpdate;
        saveBtn.classList.add('is-editing');
        if (editBtn) editBtn.disabled = false;
    } else {
        statusEl.innerHTML = `<span style="color:#28a745;">${UI.routeCreate}</span>`;
        saveBtn.textContent = UI.routeSave;
        saveBtn.classList.remove('is-editing');
        if (editBtn) editBtn.disabled = true;
    }
    refreshMarkerStyles();
    updateSelectionUI();
    document.getElementById('selection-panel').scrollTop = 0;
}

function toggleCreateRouteModal(show) {
    const modal = document.getElementById('createRouteModal');
    if (!modal) return;
    modal.style.display = show ? 'flex' : 'none';
}

function openCreateRouteModal() {
    ensureCreateRouteModal();
    syncCreateRouteManagerSelect();
    syncCreateRouteWarehouseVisibility();
    const nameInput = document.getElementById('create_route_name');
    const errors = document.getElementById('createRouteErrors');
    if (nameInput) nameInput.value = '';
    document.querySelectorAll('input[name="create_legal_entity_code"]').forEach((input) => { input.checked = false; });
    document.getElementById('create_legal_entity')?.classList.remove('field-error');
    if (errors) {
        errors.style.display = 'none';
        errors.innerHTML = '';
    }
    const sourceSelect = document.getElementById('create_source_warehouse_id');
    const destSelect = document.getElementById('create_destination_warehouse_id');
    if (sourceSelect) sourceSelect.innerHTML = buildWarehouseOptions('', '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0441\u043a\u043b\u0430\u0434 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u044f');
    if (destSelect) destSelect.innerHTML = buildWarehouseOptions('', '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0441\u043a\u043b\u0430\u0434 \u043d\u0430\u0437\u043d\u0430\u0447\u0435\u043d\u0438\u044f');
    toggleCreateRouteModal(true);
    if (nameInput) setTimeout(() => nameInput.focus(), 0);
}

function syncCreateRouteWarehouseVisibility() {
    const routeTypeInput = document.getElementById('create_route_type');
    const routeType = normalizeRouteType(routeTypeInput ? routeTypeInput.value : 'generator_to_utilizer', 'OO');
    const sourceWrap = document.getElementById('create_source_warehouse_wrap');
    const destWrap = document.getElementById('create_destination_warehouse_wrap');
    const warehouseGroup = document.getElementById('create_warehouse_group');
    const showSource = routeType === 'warehouse_to_warehouse' || routeType === 'warehouse_to_utilizer';
    const showDest = routeType === 'generator_to_warehouse' || routeType === 'warehouse_to_warehouse';
    if (sourceWrap) sourceWrap.style.display = showSource ? 'block' : 'none';
    if (destWrap) destWrap.style.display = showDest ? 'block' : 'none';
    if (warehouseGroup) {
        const hasVisibleFields = showSource || showDest;
        warehouseGroup.style.display = hasVisibleFields ? 'grid' : 'none';
        warehouseGroup.classList.toggle('is-visible', hasVisibleFields);
    }
}

function closeCreateRouteModal() {
    toggleCreateRouteModal(false);
}

async function submitRoutePayload(payload, btn, restoreText) {
    if (btn) {
        btn.disabled = true;
        btn.textContent = UI.msgSaving;
    }
    try {
        const res = await postRouteAction(payload.action || 'save', payload);
        if (res && res.success) {
            alert(res.message);
            window.location.reload();
            return;
        }
        alert(UI.msgErrorPrefix + ((res && res.message) ? res.message : UI.msgSaveFailed));
    } catch (e) {
        console.error('submitRoutePayload error:', e);
        alert(UI.msgNetworkError);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = restoreText;
        }
    }
}

function confirmCreateRoute() {
    const nameInput = document.getElementById('create_route_name');
    const managerInput = document.getElementById('create_route_manager_id');
    const routeTypeInput = document.getElementById('create_route_type');
    const sourceWarehouseInput = document.getElementById('create_source_warehouse_id');
    const destinationWarehouseInput = document.getElementById('create_destination_warehouse_id');
    const legalEntityInput = document.querySelector('input[name="create_legal_entity_code"]:checked');
    const errorBox = document.getElementById('createRouteErrors');
    const saveBtn = document.getElementById('saveRouteBtn');

    const routeName = String(nameInput?.value || '').trim();
    const managerId = String(managerInput?.value || '').trim();
    const routeType = normalizeRouteType(routeTypeInput?.value || 'generator_to_utilizer', 'OO');
    const sourceWarehouseId = sourceWarehouseInput?.value || '';
    const destinationWarehouseId = destinationWarehouseInput?.value || '';
    if (errorBox) {
        errorBox.style.display = 'none';
        errorBox.innerHTML = '';
    }

    if (!routeName) {
        if (errorBox) {
            errorBox.style.display = 'block';
            errorBox.textContent = UI.msgCreateRouteTitleRequired;
        }
        if (nameInput) nameInput.focus();
        return;
    }
    if (!managerId) {
        if (errorBox) {
            errorBox.style.display = 'block';
            errorBox.textContent = UI.msgChooseManagerForRoute;
        }
        if (managerInput) managerInput.focus();
        return;
    }
    if (!legalEntityInput) {
        if (errorBox) {
            errorBox.style.display = 'block';
            errorBox.textContent = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u044e\u0440\u0438\u0434\u0438\u0447\u0435\u0441\u043a\u043e\u0435 \u043b\u0438\u0446\u043e \u0440\u0435\u0439\u0441\u0430';
        }
        document.getElementById('create_legal_entity')?.classList.add('field-error');
        return;
    }
    if (routeType === 'generator_to_warehouse' && !destinationWarehouseId) {
        if (errorBox) {
            errorBox.style.display = 'block';
            errorBox.textContent = '\u0423\u043a\u0430\u0436\u0438\u0442\u0435 \u0441\u043a\u043b\u0430\u0434 \u043d\u0430\u0437\u043d\u0430\u0447\u0435\u043d\u0438\u044f \u0434\u043b\u044f \u0432\u044b\u0433\u0440\u0443\u0437\u043a\u0438 \u043d\u0430 \u0441\u043a\u043b\u0430\u0434';
        }
        return;
    }

    const costVal = document.getElementById('route-cost-input')?.value.trim() || '';
    const payload = {
        zayavki_ids: selectedOrder.join(','),
        name: routeName,
        assigned_manager_id: Number(managerId),
        cost: (costVal !== '' && !isNaN(costVal)) ? parseFloat(costVal) : null,
        unload_type: resolveUnloadTypeByRouteType(routeType),
        route_type: routeType,
        legal_entity_code: legalEntityInput.value,
        source_warehouse_id: sourceWarehouseId,
        destination_warehouse_id: destinationWarehouseId
    };
    closeCreateRouteModal();
    submitRoutePayload(payload, saveBtn, UI.routeSave);
}

function promptSaveRoute() {
    if (selectedOrder.length < 1) return alert(UI.msgPickAtLeastOneRequest);
    const saveBtn = document.getElementById('saveRouteBtn');
    const costVal = document.getElementById('route-cost-input').value.trim();

    if (editingRouteId) {
        const activeNameEl = document.querySelector('.route-item.active .route-name');
        const currentName = activeNameEl ? activeNameEl.textContent.replace(/^#\d+\s/, '') : '';
        const activeRouteEl = document.querySelector('.route-item.active[data-route-id]');
        const routeMetaKey = String(editingRouteId);
        const cachedMeta = (routeCardsMeta && routeCardsMeta[routeMetaKey]) ? routeCardsMeta[routeMetaKey] : {};
        const existingRouteType = cachedMeta.route_type || '';
        const existingUnloadType = cachedMeta.unload_type || 'OO';
        const existingSourceWarehouseId = cachedMeta.source_warehouse_id || '';
        const existingDestinationWarehouseId = cachedMeta.destination_warehouse_id || '';
        const normalizedRouteType = normalizeRouteType(existingRouteType, existingUnloadType);
        const existingDriverId = cachedMeta.driver_id || '';
        const existingAssignedManagerId = cachedMeta.assigned_manager_id || '';
        const existingLegalEntityCode = cachedMeta.legal_entity_code || '';
        const existingCost = cachedMeta.cost ?? '';
        const existingDatesFrom = cachedMeta.planned_start_date_from || '';
        const existingDatesTo = cachedMeta.planned_start_date_to || '';
        const payload = {
            id: editingRouteId,
            name: currentName,
            zayavki_ids: selectedOrder.join(','),
            driver_id: existingDriverId,
            assigned_manager_id: existingAssignedManagerId,
            legal_entity_code: existingLegalEntityCode,
            cost: (costVal !== '' && !isNaN(costVal)) ? parseFloat(costVal) : (existingCost !== '' ? existingCost : null),
            planned_start_date_from: existingDatesFrom,
            planned_start_date_to: existingDatesTo,
            unload_type: resolveUnloadTypeByRouteType(normalizedRouteType),
            route_type: normalizedRouteType,
            source_warehouse_id: existingSourceWarehouseId,
            destination_warehouse_id: existingDestinationWarehouseId
        };
        submitRoutePayload(payload, saveBtn, UI.routeUpdate);
        return;
    }

    openCreateRouteModal();
}

async function deleteRoute(id) {
    if(!confirm(UI.msgDeleteRouteConfirm)) return;
    try {
        const res = await postRouteAction('delete_route', {id:id});
        if(res.success) {
            window.location.reload();
        } else {
            alert((res && res.message) ? res.message : 'Ошибка удаления');
        }
    } catch(e) {
        console.error('deleteRoute error:', e);
        alert('Ошибка сети при удалении');
    }
}

function calculateRoute() {
    if (selectedOrder.length < 2) return alert(UI.msgMinTwoRequests);
    
    let pointsOrder = [];
    
    selectedOrder.forEach(id => {
        groupsData.forEach(g => {
            g.points.forEach(p => {
                if (String(p.zayavka_id) === String(id)) {
                    pointsOrder.push({id: p.zayavka_id, coords: [parseFloat(p.mno_d), parseFloat(p.mno_sh)]});
                }
            });
        });
    });
    
    if (pointsOrder.length < 2) return alert(UI.msgCoordsNotFound);
    if(multiRoute) map.geoObjects.remove(multiRoute);
    
    let refs = pointsOrder.map(p => p.coords);
    refs.push(UNLOAD_ADDRESS);
    
    multiRoute = new ymaps.multiRouter.MultiRoute({
        referencePoints: refs,
        routingMode: 'auto'
    }, {
        boundsAutoApply: true,
        routeActiveStrokeWidth: 5,
        routeActiveStrokeColor: "#007bff",
        wayPointStartVisible: false,
        wayPointFinishVisible: false,
        viaPointVisible: false
    });
    
    map.geoObjects.add(multiRoute);
    
    multiRoute.model.events.add('requestsuccess', function() {
        const route = multiRoute.getRoutes().get(0);
        if(route) {
            const dist = route.properties.get('distance').value;
            const dur = route.properties.get('duration').value;
            const h = Math.floor(dur/3600), m = Math.floor((dur%3600)/60);
            document.getElementById('route-info-container').innerHTML = `<div class="route-info"><div class="route-info-item">${UI.ruler} ${dist>1000?(dist/1000).toFixed(2)+' '+UI.km:dist+' '+UI.m}</div><div class="route-info-item">${UI.clock} ${h>0?h+UI.hourShort+' ':''}${m} ${UI.min}</div></div>`;
        }
    });
}

ymaps.ready(init);
function init() {
    map = new ymaps.Map('map', {center: [55.751574, 37.573856], zoom: 10, controls: ['zoomControl', 'fullscreenControl']});
    groupsData = Array.isArray(bootstrapGroupsData) ? bootstrapGroupsData : [];
    Object.keys(bootstrapFlightStatusList).forEach(function(statusKey) {
        flightStatusFilters[statusKey] = !(statusKey === 'started' || statusKey === 'completed');
    });
    customLayerFilters['default'] = true;
    Object.keys(bootstrapCustomLayers).forEach(function(layerName) {
        customLayerFilters[layerName] = true;
    });
    
    // === CREATE COLLECTIONS ===
    requestsCollection = new ymaps.GeoObjectCollection();
    warehousesCollection = new ymaps.GeoObjectCollection();
    trackersCollection = new ymaps.GeoObjectCollection();
    
    // Add in strict order: requests, warehouses, then transport
    map.geoObjects.add(requestsCollection);
    map.geoObjects.add(warehousesCollection);
    map.geoObjects.add(trackersCollection);
    
    groupsData.forEach(group => group.points.forEach(p => weightById[p.zayavka_id] = Math.round(p.mass_netto * 1000)));
    
    // === INITIALIZE REQUEST MARKERS ===
    groupsData.forEach(group => {
        const placemark = new ymaps.Placemark(
            [parseFloat(group.mno_d), parseFloat(group.mno_sh)],
            {
                balloonContent: createBalloonContent(group),
                hintContent: group.count > 1 ? `${UI.group}: ${Math.round(group.total_mass * 1000)} ${UI.kg}` : `${TXT.request}: ${Math.round(group.total_mass * 1000)} ${UI.kg}`,
                groupData: group
            },
            {
                iconLayout: 'default#image',
                iconImageHref: '',
                iconImageSize: [50, 45],
                iconImageOffset: [-25, -45],
                balloonOffset: [0, -30],
                visible: false,
                zIndex: 500 // Requests are below transport markers
            }
        );
        placemark.events.add('click', function(e) {
            if (popupsDisabled) e.preventDefault();
            toggleGroupSelection(group);
        });
        placemarks.push(placemark);
        requestsCollection.add(placemark);
    });
    
    // === INITIALIZE TRANSPORT MARKERS ===
    loadRecentActivatedTrackers().finally(() => {
        renderTrackersByMode();
    });
    
    // === REFRESH BUTTON SETUP ===
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', fetchTrackers);
    }
    const transportModeRadios = document.querySelectorAll('input[name="transportMode"]');
    transportModeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this && this.checked) {
                setTransportDisplayMode(this.value);
            }
        });
    });
    const managerScopeSelect = document.getElementById('managerScopeSelect');
    if (managerScopeSelect) {
        managerScopeSelect.addEventListener('change', async function() {
            managerScopeId = String(this.value || '').trim();
            if (managerScopeId) {
                localStorage.setItem(MANAGER_STORAGE_KEY, managerScopeId);
            } else {
                localStorage.removeItem(MANAGER_STORAGE_KEY);
            }
            await loadPlannedRoutes();
        });
    }
    ensureCreateRouteModal();
    const createRouteConfirmBtn = document.getElementById('createRouteConfirmBtn');
    const createRouteCancelBtn = document.getElementById('createRouteCancelBtn');
    const createRouteModal = document.getElementById('createRouteModal');
    if (createRouteConfirmBtn) createRouteConfirmBtn.addEventListener('click', confirmCreateRoute);
    if (createRouteCancelBtn) createRouteCancelBtn.addEventListener('click', closeCreateRouteModal);
    if (createRouteModal) {
        createRouteModal.addEventListener('click', function(event) {
            if (event.target === createRouteModal) closeCreateRouteModal();
        });
    }
    document.querySelectorAll('input[name="create_legal_entity_code"]').forEach((input) => {
        input.addEventListener('change', () => {
            document.getElementById('create_legal_entity')?.classList.remove('field-error');
        });
    });
    document.querySelectorAll('input[name="edit_legal_entity_code"]').forEach((input) => {
        input.addEventListener('change', () => {
            document.getElementById('edit_legal_entity')?.classList.remove('field-error');
            updateFlightModalSummary();
        });
    });
    const addSourceWarehouseBtn = document.getElementById('add_source_warehouse_btn');
    const addDestinationWarehouseBtn = document.getElementById('add_destination_warehouse_btn');
    const addDriverBtn = document.getElementById('add_driver_btn');
    const driverSelect = document.getElementById('edit_driver_id');
    const driverInput = document.getElementById('edit_driver_input');
    const driverToggle = document.getElementById('edit_driver_toggle');
    const driverMenu = document.getElementById('edit_driver_menu');
    if (addSourceWarehouseBtn) addSourceWarehouseBtn.addEventListener('click', () => openWarehouseCreateModal('edit_source_warehouse_id'));
    if (addDestinationWarehouseBtn) addDestinationWarehouseBtn.addEventListener('click', () => openWarehouseCreateModal('edit_destination_warehouse_id'));
    if (addDriverBtn) addDriverBtn.addEventListener('click', openDriverCreateModal);
    if (driverInput) {
        driverInput.addEventListener('focus', showDriverMenu);
        driverInput.addEventListener('input', () => {
            if (driverSelect) driverSelect.value = '';
            renderDriverMenu(driverInput.value);
            showDriverMenu();
        });
        driverInput.addEventListener('blur', () => {
            setTimeout(() => {
                hideDriverMenu();
                validateDriverComboboxSelection();
            }, 120);
        });
        driverInput.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                showDriverMenu();
            }
        });
    }
    if (driverToggle) {
        driverToggle.addEventListener('click', () => {
            if (driverMenuVisible) hideDriverMenu(); else showDriverMenu();
        });
    }
    if (driverMenu) {
        driverMenu.addEventListener('mousedown', (event) => {
            const target = event.target && event.target.closest ? event.target.closest('.driver-combobox-item[data-driver-id]') : null;
            if (!target) return;
            const id = String(target.getAttribute('data-driver-id') || '');
            selectDriverValue(id, true);
            hideDriverMenu();
            updateFlightModalSummary();
        });
    }
    if (driverSelect) {
        driverSelect.addEventListener('change', () => {
            selectDriverValue(driverSelect.value, true);
            updateFlightModalSummary();
        });
    }
    const warehouseCreateModal = document.getElementById('warehouseCreateModal');
    const warehouseCreateSaveBtn = document.getElementById('warehouseCreateSaveBtn');
    const warehouseCreateCancelBtn = document.getElementById('warehouseCreateCancelBtn');
    const warehouseCreateCloseTopBtn = document.getElementById('warehouseCreateCloseTopBtn');
    const warehouseGeocodeBtn = document.getElementById('warehouseGeocodeBtn');
    const warehouseAddressInput = document.getElementById('new_warehouse_address');
    const warehouseGeocodeNote = document.getElementById('warehouseGeocodeNote');
    if (warehouseCreateSaveBtn) warehouseCreateSaveBtn.addEventListener('click', saveWarehouseFromModal);
    if (warehouseCreateCancelBtn) warehouseCreateCancelBtn.addEventListener('click', closeWarehouseCreateModal);
    if (warehouseCreateCloseTopBtn) warehouseCreateCloseTopBtn.addEventListener('click', closeWarehouseCreateModal);
    if (warehouseGeocodeBtn) warehouseGeocodeBtn.addEventListener('click', geocodeWarehouseAddress);
    if (warehouseAddressInput) {
        warehouseAddressInput.addEventListener('input', () => {
            if (warehouseAddressGeocoded && warehouseGeocodeNote) {
                warehouseGeocodeNote.style.display = 'block';
                warehouseGeocodeNote.textContent = UI.msgWarehouseGeocodeChanged;
            }
        });
    }
    if (warehouseCreateModal) {
        warehouseCreateModal.addEventListener('click', function(event) {
            if (event.target === warehouseCreateModal) closeWarehouseCreateModal();
        });
    }
    const driverCreateModal = document.getElementById('driverCreateModal');
    const driverCreateSaveBtn = document.getElementById('driverCreateSaveBtn');
    const driverCreateCancelBtn = document.getElementById('driverCreateCancelBtn');
    const driverCreateCloseTopBtn = document.getElementById('driverCreateCloseTopBtn');
    const driverGpsTypeInputs = document.querySelectorAll('input[name="new_driver_gps_type"]');
    const newDriverFullName = document.getElementById('new_driver_full_name');
    const newDriverPlate = document.getElementById('new_driver_vehicle_number');
    if (driverCreateSaveBtn) driverCreateSaveBtn.addEventListener('click', saveDriverFromModal);
    if (driverCreateCancelBtn) driverCreateCancelBtn.addEventListener('click', closeDriverCreateModal);
    if (driverCreateCloseTopBtn) driverCreateCloseTopBtn.addEventListener('click', closeDriverCreateModal);
    if (driverGpsTypeInputs.length) {
        driverGpsTypeInputs.forEach((input) => input.addEventListener('change', syncDriverCreateGpsType));
    }
    if (newDriverFullName) {
        newDriverFullName.addEventListener('input', () => {
            const normalized = normalizeDriverNameInput(newDriverFullName.value);
            if (newDriverFullName.value !== normalized) newDriverFullName.value = normalized;
        });
        newDriverFullName.addEventListener('blur', () => {
            const value = String(normalizeDriverNameFinal(newDriverFullName.value)).trim();
            newDriverFullName.value = value;
            setInlineFieldError(
                'new_driver_full_name_error',
                isValidDriverFullName(value) || !value
                    ? ''
                    : UI.msgDriverNameInvalid
            );
        });
    }
    if (newDriverPlate) {
        newDriverPlate.addEventListener('input', () => {
            const normalized = normalizeDriverPlateInput(newDriverPlate.value);
            if (newDriverPlate.value !== normalized) newDriverPlate.value = normalized;
        });
        newDriverPlate.addEventListener('blur', () => {
            const value = normalizeDriverPlateInput(newDriverPlate.value);
            newDriverPlate.value = value;
            setInlineFieldError(
                'new_driver_vehicle_number_error',
                isValidDriverPlate(value) || !value
                    ? ''
                    : UI.msgDriverPlateInvalid
            );
        });
    }
    // Driver mini-modal intentionally does not close on overlay click.
    document.addEventListener('click', (event) => {
        const combo = document.getElementById('edit_driver_combobox');
        if (!combo) return;
        if (!combo.contains(event.target)) {
            hideDriverMenu();
        }
    });

    const saveEditBtn = document.getElementById('flightEditSaveBtn');
    const cancelEditBtn = document.getElementById('flightEditCancelBtn');
    const transferFoundBtn = document.getElementById('flightEditTransferFoundBtn');
    const transferStartedBtn = document.getElementById('flightEditTransferStartedBtn');
    const backToPlannedBtn = document.getElementById('flightEditBackToPlannedBtn');
    const backToFoundBtn = document.getElementById('flightEditBackToFoundBtn');
    const toCompletedBtn = document.getElementById('flightEditToCompletedBtn');
    const deleteBtn = document.getElementById('flightEditDeleteBtn');
    const closeTopBtn = document.getElementById('flightEditCloseTopBtn');
    if (saveEditBtn) saveEditBtn.addEventListener('click', saveFlightEdit);
    if (cancelEditBtn) cancelEditBtn.addEventListener('click', closeFlightEditModal);
    if (transferFoundBtn) {
        transferFoundBtn.addEventListener('click', () => {
            const idInput = document.getElementById('edit_flight_id');
            const sourceInput = document.getElementById('edit_flight_source');
            const routeId = Number(idInput ? idInput.value : 0);
            const source = sourceInput ? sourceInput.value : '';
            if (source !== 'planned') return;
            if (routeId > 0) transferPlannedToFound(routeId);
        });
    }
    if (transferStartedBtn) {
        transferStartedBtn.addEventListener('click', () => {
            const idInput = document.getElementById('edit_flight_id');
            const routeId = Number(idInput ? idInput.value : 0);
            const preErrors = validateRequiredForStrictTransition();
            if (Object.keys(preErrors).length > 0) {
                showFlightValidationErrors(preErrors);
                return;
            }
            if (routeId > 0) saveBeforeStatusTransition((id) => openStartConfirmModal(id, 'started'), routeId);
        });
    }
    if (backToPlannedBtn) {
        backToPlannedBtn.addEventListener('click', () => {
            const idInput = document.getElementById('edit_flight_id');
            const routeId = Number(idInput ? idInput.value : 0);
            if (routeId > 0) saveBeforeStatusTransition(transferToPlanned, routeId);
        });
    }
    if (backToFoundBtn) {
        backToFoundBtn.addEventListener('click', () => {
            const idInput = document.getElementById('edit_flight_id');
            const routeId = Number(idInput ? idInput.value : 0);
            if (routeId > 0) saveBeforeStatusTransition(transferToFound, routeId);
        });
    }
    if (toCompletedBtn) {
        toCompletedBtn.addEventListener('click', () => {
            const idInput = document.getElementById('edit_flight_id');
            const routeId = Number(idInput ? idInput.value : 0);
            if (routeId > 0) saveBeforeStatusTransition(transferToCompleted, routeId);
        });
    }
    if (deleteBtn) {
        deleteBtn.addEventListener('click', () => {
            const idInput = document.getElementById('edit_flight_id');
            const routeId = Number(idInput ? idInput.value : 0);
            if (routeId > 0) deleteRoute(routeId);
        });
    }
    if (closeTopBtn) closeTopBtn.addEventListener('click', closeFlightEditModal);
    const routeTypeInput = document.getElementById('edit_route_type');
    if (routeTypeInput) {
        routeTypeInput.addEventListener('change', () => {
            syncRouteTypeFieldsVisibility(routeTypeInput.value);
            updateFlightModalSummary();
        });
    }
    const createRouteTypeInput = document.getElementById('create_route_type');
    if (createRouteTypeInput) {
        createRouteTypeInput.addEventListener('change', () => {
            syncCreateRouteWarehouseVisibility();
        });
    }

    const startConfirmBtn = document.getElementById('startConfirmBtn');
    const startCancelBtn = document.getElementById('startCancelBtn');
    if (startConfirmBtn) startConfirmBtn.addEventListener('click', confirmTransferToStarted);
    if (startCancelBtn) startCancelBtn.addEventListener('click', closeStartConfirmModal);
if (startCancelBtn) startCancelBtn.addEventListener('click', closeStartConfirmModal);
    // Driver select triggers change event (not input) — rebuild preview explicitly
    const driverSelectEl = document.getElementById('edit_driver_id');
    if (driverSelectEl) {
        driverSelectEl.addEventListener('change', () => {
            updateFlightModalSummary();
            const previewEl = document.getElementById('flightChangePreview');
            const previewHtml = buildFoundChangePreview(currentEditingMeta);
            if (previewEl) {
                if (previewHtml) {
                    previewEl.style.display = 'block';
                    previewEl.innerHTML = previewHtml;
                } else {
                    previewEl.style.display = 'none';
                    previewEl.innerHTML = '';
                }
            }
        });
    }
    ['edit_driver_id', 'edit_planned_start_date_from', 'edit_planned_start_date_to', 'edit_actual_start_date', 'edit_actual_end_date', 'edit_cost', 'edit_zayavki_ids', 'edit_route_type', 'edit_source_warehouse_id', 'edit_destination_warehouse_id'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', () => {
                el.classList.remove('field-error');
                const errWrap = document.getElementById('flightValidationErrors');
                if (errWrap) {
                    errWrap.style.display = 'none';
                    errWrap.innerHTML = '';
                }
                updateFlightModalSummary();
                const previewEl = document.getElementById('flightChangePreview');
                const previewHtml = buildFoundChangePreview(currentEditingMeta);
                if (previewEl) {
                    if (previewHtml) {
                        previewEl.style.display = 'block';
                        previewEl.innerHTML = previewHtml;
                    } else {
                        previewEl.style.display = 'none';
                        previewEl.innerHTML = '';
                    }
                }
            });
        }
    });
    
    // === AUTO REFRESH TRACKERS EVERY 60 SECONDS ===
    trackerUpdateInterval = setInterval(fetchTrackers, 60000);
    
    window.addEventListener('beforeunload', () => {
        if (trackerUpdateInterval) clearInterval(trackerUpdateInterval);
    });
    
    updateMap();
    loadManagers();
    const warehouseLayerCheckbox = document.getElementById('warehouse_layer_visible');
    warehousesVisible = warehouseLayerCheckbox ? !!warehouseLayerCheckbox.checked : true;
    loadWarehouses();
    
    if (placemarks.length > 0 || trackerPlacemarks.length > 0) {
        const bounds = map.geoObjects.getBounds();
        if (bounds) map.setBounds(bounds, { checkZoomRange: true, zoomMargin: 50 });
    }
}
