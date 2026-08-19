@availability @availability_otheractivity
Feature: Restrict access by completion of an activity in another course
  In order to build cross-course prerequisites
  As a teacher
  I need to restrict activities by completion of an activity anywhere on the site

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
      | Course 2 | C2        | 0        | 1                |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher1 | C2     | editingteacher |
      | student1 | C1     | student        |
      | student1 | C2     | student        |
    And the following "activities" exist:
      | activity | course | name        | idnumber | completion |
      | page     | C1     | Prereq page | PREREQ   | 1          |
    And the following "activities" exist:
      | activity | course | name        | idnumber |
      | page     | C2     | Locked page | LOCKED   |

  Scenario: A student cannot access the restricted activity before completing the other activity
    Given the "LOCKED" activity is restricted by completion of the "PREREQ" activity
    When I am on the "C2" "Course" page logged in as "student1"
    Then I should see "Not available unless: The activity Prereq page (Course 1) is marked complete"
    And "Locked page" "link" should not exist

  @javascript
  Scenario: Completing the other activity opens the restricted activity
    Given the "LOCKED" activity is restricted by completion of the "PREREQ" activity
    When I am on the "PREREQ" "Activity" page logged in as "student1"
    And I toggle the manual completion state of "Prereq page"
    And I am on the "C2" "Course" page
    Then "Locked page" "link" should exist

  @javascript
  Scenario: A teacher restricts an activity using the search dialog
    Given I am on the "LOCKED" "Activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I press "Add restriction..."
    And I click on "Other activity" "button" in the "Add restriction..." "dialogue"
    And I set the field "Search activities" to "Prereq"
    And I wait until "//select[@name='cm']/option[contains(., 'Prereq page [Course 1]')]" "xpath_element" exists
    And I select the first search result in the other activity restriction
    And I press "Save and return to course"
    When I am on the "C2" "Course" page logged in as "student1"
    Then I should see "Not available unless: The activity Prereq page (Course 1) is marked complete"
