# surveyChaining for LimeSurvey

Chaining one survey to another one.

Current purpose is validation of survey by another person.

* This plugin is compatible with 3.X version, and can be tested in 3.73 and 4.X version*

## Installtion

With token enable survey : you don't need another plugin. Without token you **need** [reloadAnyResponse](https://gitlab.com/SondagesPro/coreAndTools/reloadAnyResponse) plugin activated.

### Via GIT
- Go to your LimeSurvey Directory (version up to 2.50)
- Clone in plugins/surveyChaining directory

## Usage

In tool menu, you can access to surveyChaing management. You can choose another survey.

If the survey have some question with same code (can be sub question code too) : when the survey is submitted : a new response was created with prfilling question with the primary survey.

You can use a single choice question to choose another survey according to answer of this single choice question.

## Issues and feature

All issue and merge request must be done in [base repo](https://gitlab.com/SondagesPro/SurveyAccess/surveyChaining) (currently gitlab).

Issue must be posted with complete information : LimeSurvey version and build number, web server version, PHP version, SQL type and version number … 
**Reminder:** no warranty of functionnal system operation is offered on this software. If you want a professional offer: please [contact Sondages Pro](https://extensions.sondages.pro/about/contact.html).

## Home page & Copyright
- HomePage <https://extensions.sondages.pro/>
- Copyright © 2018-2020 Denis Chenu <http://sondages.pro>
- Copyright © 2018 DRAAF Bourgogne - Franche-Comté <http://draaf.bourgogne-franche-comte.agriculture.gouv.fr/>
- Licence : GNU General Public License <https://www.gnu.org/licenses/gpl-3.0.html>
- [![Donate](https://liberapay.com/assets/widgets/donate.svg)](https://liberapay.com/SondagesPro/) : [Donate on Liberapay](https://liberapay.com/SondagesPro/)
