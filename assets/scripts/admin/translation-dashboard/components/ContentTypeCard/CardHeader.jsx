const CardHeader = ({ icon, title, name, completionPercentage }) => {
  return (
    <div className="pllat-flex pllat-items-center pllat-justify-between pllat-mb-4 pllat-pb-3 pllat-border-b pllat-border-gray-200">
      <div className="pllat-flex pllat-items-center">
        <span className={`dashicons ${icon} pllat-mr-2 pllat-text-gray-600`}></span>
        <h3 className="pllat-text-base pllat-font-semibold pllat-mt-0 pllat-mb-0">
          {title} <span className="pllat-text-sm pllat-text-gray-500 pllat-font-normal">({name})</span>
        </h3>
      </div>
      <span className="pllat-text-sm pllat-text-gray-500">{completionPercentage}%</span>
    </div>
  );
};

export default CardHeader;
