import { render } from "@wordpress/element";
import TranslationDashboard from "./components/TranslationDashboard";
import { useDashboardActions } from "./hooks/useDashboardActions";
import TabNavigation from "./components/TabNavigation";
import StringsTab from "./components/StringsTab";
import TranslationActions from "./components/ContentTypeCard/TranslationActions";
import BulkConfigModal from "./components/BulkConfigModal";

const root = document.getElementById("pllat_translation_dashboard");

if (root) {
	render(
		<TranslationDashboard
			useActions={useDashboardActions}
			TabNavigation={TabNavigation}
			tabs={{ strings: StringsTab }}
			CardActions={TranslationActions}
			ConfigModal={BulkConfigModal}
		/>,
		root
	);
}
